# RouteCollector

`RouteCollector` is an independent builder. It collects mutable [RouteDefinition](/api/route#routedefinition) objects and builds a runtime [Router](/api/router). It does not extend `Router`.

```php
use Solo\Router\RouteCollector;

$routes = new RouteCollector();
$routes->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show')
    ->middleware(AuthMiddleware::class);

$router = $routes->build();
```

## HTTP method shortcuts

Each shortcut accepts `(string $path, mixed $handler)` and returns a mutable `RouteDefinition`:

| Method | HTTP method |
|--------|-------------|
| `get()` | GET |
| `head()` | HEAD |
| `post()` | POST |
| `put()` | PUT |
| `patch()` | PATCH |
| `delete()` | DELETE |
| `options()` | OPTIONS |

```php
$routes->get('/users', [UserController::class, 'index']);
$routes->post('/users', [UserController::class, 'store']);
$routes->put('/users/{id}', [UserController::class, 'update']);
$routes->patch('/users/{id}', [UserController::class, 'patch']);
$routes->delete('/users/{id}', [UserController::class, 'destroy']);
$routes->head('/status', [StatusController::class, 'head']);
$routes->options('/{any:.*}', [CorsController::class, 'preflight']);
```

HEAD falls back to GET when no explicit HEAD route matches the requested path.

## addRoute()

```php
public function addRoute(
    string $method,
    string $path,
    mixed $handler,
    array $options = []
): RouteDefinition
```

Registers a definition. Method names are normalized to uppercase; unsupported methods throw `InvalidArgumentException` immediately.

| Option | Type | Default | Meaning |
|--------|------|---------|---------|
| `group` | `string` | `''` | Additional URL prefix |
| `middlewares` | `array` | `[]` | Route middleware |
| `name` | `string\|null` | `null` | Route name |
| `attributes` | `array` | `[]` | Custom attributes |

```php
$routes->addRoute('GET', '/users/{id}', [UserController::class, 'show'], [
    'group' => '/api',
    'middlewares' => [AuthMiddleware::class],
    'name' => 'users.show',
    'attributes' => ['permission' => 'users.view'],
]);
```

Inside an active group, `addRoute()` combines the group prefix with the `group` option, appends the option middleware after group middleware, and lets option attributes override inherited attributes by key.

Duplicate paths/names and invalid path syntax or regexes are rejected at `build()`. Attribute keys must be names: integer keys or integer strings such as `'42'` are rejected when assigned. Middleware keys must be positional: a string key in the `middlewares` option throws `InvalidArgumentException` immediately, since it usually means attributes were passed in the wrong option.

## group()

```php
public function group(
    string $prefix,
    callable $callback,
    array $middlewares = [],
    array $attributes = []
): void
```

The callback receives this collector with the group context applied. Prefixes concatenate. Middleware accumulates from outer groups to inner groups, then the route. An inner group or route attribute replaces an outer value with the same key.

```php
$routes->group('/api', function (RouteCollector $routes) {
    $routes->group('/v1', function (RouteCollector $routes) {
        $routes->get('/users', [UserController::class, 'index']);
    }, [RateLimitMiddleware::class]);
}, [ApiAuthMiddleware::class], ['area' => 'api']);
```

String keys in group middleware or integer keys in group attributes throw `InvalidArgumentException`, helping catch swapped argument arrays. Group context is restored after the callback, including when it throws.

## build()

```php
public function build(): Router
```

Validates definitions, creates runtime routes, and compiles one pattern per method. These throw `InvalidArgumentException` here:

- duplicate names and duplicate method/full-path pairs;
- a route that can never match because an earlier route with the same method has the same pattern, e.g. `/users/{uid}` after `/users/{id}`;
- malformed paths, invalid parameter regexes, and [unsupported regex syntax](/features/parameters#validation);
- a route whose pattern PCRE cannot compile, e.g. because it has more than 65,535 parameters.

```php
$definition = $routes->get('/profile', [ProfileController::class, 'show'])
    ->name('profile');

$first = $routes->build();
$definition->name('account')->middleware(AuthMiddleware::class);
$second = $routes->build();

$first->getRouteByName('profile'); // Original Route snapshot
$first->getRouteByName('account'); // null
$second->getRouteByName('account'); // New Route snapshot with auth middleware
```

Later registrations and fluent edits affect subsequent builds only. Build once after configuration and reuse that router when your application's process lifetime permits it.

Route metadata is fixed when building: arrays are PHP values, so later edits to a definition do not change an existing router. The router does not deep-clone handler objects, closures, object-valued attributes, or values stored through PHP references inside arrays.

## getRoutes()

```php
public function getRoutes(): array
```

Returns `list<RouteDefinition>` in registration order for inspecting or configuring the builder. These are the original mutable definitions. `Router::getRoutes()` instead returns runtime `Route` snapshots.

The collector has no `resolve()`, `match()`, or `getRouteByName()` method. Build a router to resolve requests or look up the final names.
