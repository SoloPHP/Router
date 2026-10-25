# Migrating from v3 to v4

Version 4 deliberately breaks the mutable v3 API. It introduces a separate builder, immutable runtime routes, typed resolution results, and build-time validation. Advanced route syntax and matching priority remain supported.

## Requirements

Version 4 requires PHP 8.3 or later; v3 supported PHP 8.1. Projects on PHP 8.1 or 8.2 can stay on 3.5.

## Build after configuration

In v3 a `RouteCollector` was also a mutable `Router`. In v4 these are separate objects:

```php
use Solo\Router\RouteCollector;

$routes = new RouteCollector();
$routes->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show')
    ->middleware(AuthMiddleware::class);

$router = $routes->build();
$result = $router->resolve('GET', '/users/42');
```

Move registration calls from `Router` to `RouteCollector`. Keep `get()`, `post()`, other HTTP shortcuts, `group()`, and `addRoute()` on the collector. Call `build()` after registering and configuring routes. The collector cannot resolve requests or look up routes by their final names.

## Handle typed results

Replace `match(): array|false` and a follow-up allowed-method lookup with `resolve()`:

```php
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;
use Solo\Router\Results\NotFound;

$result = $router->resolve($method, $uri);

if ($result instanceof Matched) {
    $handler = $result->route->handler;
    $params = $result->params;
    $middlewares = $result->route->middlewares;
} elseif ($result instanceof MethodNotAllowed) {
    http_response_code(405);
    header('Allow: ' . implode(', ', $result->allowedMethods));
} elseif ($result instanceof NotFound) {
    http_response_code(404);
}
```

All result values are objects. Do not use `if ($result)` to test for a match: use `instanceof Matched`.

| v3 access | v4 access |
|-----------|-----------|
| `$match['handler']` | `$result->route->handler` |
| `$match['params']` | `$result->params` |
| `$match['middlewares']` | `$result->route->middlewares` |
| `$match['name']` | `$result->route->name` |
| `$match['attributes']` | `$result->route->attributes` |

`getAllowedMethods($uri)` remains available for callers that need that information separately.

## Separate definitions from runtime routes

Registration methods return `RouteDefinition`, which retains `name()`, `middleware()`, `attribute()`, and the existing metadata getters. Update type declarations in route-configuration helpers from `Route` to `RouteDefinition`.

`Router::getRoutes()`, `Router::getRouteByName()`, and `Matched::$route` expose runtime `Route` objects with readonly metadata:

| v3 runtime access | v4 runtime access |
|-------------------|-------------------|
| `$route->getName()` | `$route->name` |
| `$route->getMiddlewares()` | `$route->middlewares` |
| `$route->getAttributes()` | `$route->attributes` |
| `$route->fullPath` | `$route->path` |
| `$route->group . $route->path` | `$route->path` |
| `$route->getAttribute($key, $default)` | Unchanged |

Runtime `Route` has no separate `group`, local `path`, or fluent mutation API. If you previously constructed `Route` objects directly for a router, move that setup to collector registrations and call `build()`.

## Treat each build as a snapshot

```php
$definition = $routes->get('/settings', [SettingsController::class, 'show'])
    ->name('settings');

$first = $routes->build();
$definition->name('account.settings')->attribute('area', 'account');
$second = $routes->build();

$first->getRouteByName('settings');         // Original route
$first->getRouteByName('account.settings'); // null
$second->getRouteByName('account.settings'); // Updated route
```

Replace an application-held router explicitly if configuration changes. Existing routers keep their names, middleware arrays, attributes, and route lists. Handler objects, closures, and object-valued attributes are shared application values; the snapshot does not deep-clone them.

## Move validation expectations to build()

Duplicate method/full-path pairs, duplicate names, invalid parameter names, unmatched brackets, and invalid custom regexes throw `InvalidArgumentException` at `build()`. So do routes that could never match because an earlier route with the same method has the same pattern (such as `/users/{uid}` after `/users/{id}`), and regexes with [unsupported syntax](/features/parameters#validation), such as groups that do not close inside a parameter, backtracking verbs, recursion, named groups, absolute backreferences (write `\g{-1}` instead of `\1`), comments, the `x` option, and raw groups that only set inline options (write `/(?i:about)` instead of `/(?i)about`). v3 accepted these silently.

```php
$routes->get('/users', [UserController::class, 'index'])->name('users');
$routes->get('/members', [MemberController::class, 'index'])->name('users');

$router = $routes->build(); // Throws: duplicate name
```

Tests that expected an exception from registration or the first matching request should assert the exception around `build()`. Unsupported HTTP methods, invalid attribute keys, and middleware arrays with string keys (in `group()` or the `middlewares` option of `addRoute()`) fail when assigned.

Whitespace around a parameter name and pattern is now ignored: `{id: \d+}` means `{id:\d+}`. In v3 the space became part of the regex, so such a route never matched.

Invalid regex definitions fail at build time with `InvalidArgumentException`. PCRE execution failures during `resolve()` or `getAllowedMethods()`, such as exceeding the backtracking limit for a URI, throw `RuntimeException` naming the method (`Cannot match GET routes: …`); they are not converted into 404/405 results.

## Contracts and integrations

The core package no longer depends on `solophp/contracts` and implements neither the v3 `RouterInterface` nor `AllowedMethodsInterface`. Registration helpers use `Solo\Router\RouteCollector`; request resolution uses `Solo\Router\Router`. `getAllowedMethods()` remains a direct runtime API.

Update integration contracts separately around `resolve(string $method, string $uri): Matched|NotFound|MethodNotAllowed`. Keep mutable registration on the collector contract. Handlers are opaque `mixed` values throughout the API, including HTTP shortcuts. There is no v3 compatibility adapter.

## Matching behavior

The existing advanced grammar is retained: regex constraints and groups, nested optionals, optional segments in different positions, and parameters capable of capturing `/`.

Static routes win over dynamic routes within a method. Dynamic routes retain registration order. Explicit HEAD matches take precedence over GET fallback. Incoming method matching is case-sensitive, allowed methods include HEAD for GET, and captured parameters retain the supplied bytes without percent-decoding.

## Compilation

Version 4 compiles the dynamic routes of each method into one regular expression shaped as an order-preserving prefix tree, so lookup time no longer grows with the number of routes, including routes that start with a parameter or a regex constraint. Routes that start with an optional segment are still tried one by one, though much faster than in v3. See [compiled patterns](/api/router#compiled-patterns) and [optional segments](/guide/performance#optional-segments). Building does more work than v3 did: cache the built router with [RouteCache](/features/caching), which a request loads in under 2 µs, or reuse it in long-running processes. URL generation is outside this package's API.
