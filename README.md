# Solo Router 4

PHP router with a fluent route builder, immutable runtime routes, middleware metadata, groups, and advanced optional segment patterns.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/router.svg)](https://packagist.org/packages/solophp/router)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

> Upgrading from v3? Read the [migration guide](https://solophp.github.io/Router/guide/migration-v4). Version 4 introduces a separate builder and immutable runtime.

## Features

- **Separate builder and runtime** — Configure a `RouteCollector`, then `build()` an immutable `Router` snapshot
- **Typed results** — `resolve()` returns `Matched`, `NotFound`, or `MethodNotAllowed`
- **Compiled matching** — Static routes use hash lookup; the dynamic routes of each method compile into one regex shaped as an order-preserving prefix tree
- **Fluent definitions** — Chain `name()`, `middleware()`, and `attribute()` before building
- **URL generation** — `UrlGenerator` builds the path of a named route, with encoded values checked against their patterns
- **Groups** — Nested prefixes, middleware, and attributes
- **Advanced patterns** — Optional segments anywhere, nested optionals, and regex constraints
- **Route cache** — `RouteCache` keeps a built router in a PHP file that OPcache serves in under 2 µs per request, without registering or building routes
- **Build-time validation** — Duplicate names/paths, routes that could never match, invalid syntax, and invalid regexes fail at `build()`
- **HEAD and 405 support** — HEAD falls back to GET; method failures include allowed methods

## Installation

```bash
composer require "solophp/router:^4.0"
```

Requires PHP 8.3 or later in the PHP 8 series and Composer. No runtime package dependencies. See [installation](https://solophp.github.io/Router/guide/installation) for setup and integration changes.

## Quick Example

```php
require __DIR__ . '/vendor/autoload.php';

use Solo\Router\RouteCollector;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;
use Solo\Router\UrlGenerator;

$routes = new RouteCollector();
$routes->get('/users', [UserController::class, 'index']);
$routes->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show');

$routes->post('/posts', [PostController::class, 'store'])
    ->middleware(AuthMiddleware::class)
    ->name('posts.store')
    ->attribute('permission', 'posts.create');

$routes->group('/admin', function (RouteCollector $routes) {
    $routes->get('/dashboard', [AdminController::class, 'dashboard']);
}, [AuthMiddleware::class], ['permission' => 'admin']);

$router = $routes->build();
$result = $router->resolve('GET', '/users/123');

if ($result instanceof Matched) {
    $handler = $result->route->handler;
    $params = $result->params; // ['id' => '123']
    $middlewares = $result->route->middlewares;
    $name = $result->route->name;
    $attributes = $result->route->attributes;
    // Dispatch the handler and middleware in your application.
} elseif ($result instanceof MethodNotAllowed) {
    http_response_code(405);
    header('Allow: ' . implode(', ', $result->allowedMethods));
} else {
    http_response_code(404);
}

$urls = new UrlGenerator($router);
$urls->generate('users.show', ['id' => 123]); // '/users/123'
```

Changing a definition after `build()` affects subsequent builds only. Runtime routes have readonly properties. Handlers and object-valued attributes remain application-owned objects; the router does not deep-clone them.

## Performance

Microseconds per lookup in a table of 1,000 GET routes, with PHP 8.4 and OPcache on one machine. A dash means the library does not support that layout or request with the measured API.

| Layout and request | Solo Router 4 | Leaf 5 | Symfony compiled | Slim 4 (FastRoute) |
|---|---:|---:|---:|---:|
| `/resourceN/{id}`, match | 0.43 | 0.54 | 0.57 | 2.07 |
| `/resourceN/{id}`, 404 | 0.31 | 0.33 | 0.67 | 7.53 |
| `/resourceN/{id}`, 405 | 0.44 | — | 4.39 | 2.28 |
| `/{lang:[a-z]{2}}/resourceN/{id}`, match | 0.53 | — | 0.73 | 7.61 |

Building 1,000 routes takes 1.2–2.8 ms; `RouteCache` loads a built router in under 2 µs. See the [methodology and all layouts](https://solophp.github.io/Router/guide/performance).

## Documentation

- [Quick start](https://solophp.github.io/Router/guide/quick-start)
- [Migrating from v3](https://solophp.github.io/Router/guide/migration-v4)
- [Handlers](https://solophp.github.io/Router/guide/handlers)
- [Route parameters](https://solophp.github.io/Router/features/parameters)
- [Optional segments](https://solophp.github.io/Router/features/optional-segments)
- [Groups](https://solophp.github.io/Router/features/groups)
- [Middleware](https://solophp.github.io/Router/features/middleware)
- [Named routes](https://solophp.github.io/Router/features/named-routes)
- [URL generation](https://solophp.github.io/Router/features/url-generation)
- [Attributes](https://solophp.github.io/Router/features/attributes)
- [Route cache](https://solophp.github.io/Router/features/caching)
- [Builder API](https://solophp.github.io/Router/api/route-collector)
- [Runtime API](https://solophp.github.io/Router/api/router)
- [Route and definition API](https://solophp.github.io/Router/api/route)
- [Route cache API](https://solophp.github.io/Router/api/route-cache)
- [URL generator API](https://solophp.github.io/Router/api/url-generator)

The package requires only PHP. Route matching, URL generation, metadata, compilation, and route caching are its scope; applications handle dispatch.

## Development

```bash
composer install
composer test
```

The test matrix covers PHP 8.3–8.5. See the [release notes](CHANGELOG.md) and [performance measurements](https://solophp.github.io/Router/guide/performance).

## License

MIT License. See [LICENSE](LICENSE).
