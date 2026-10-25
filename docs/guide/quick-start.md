# Quick Start

Configure routes on a `RouteCollector`, build a router, then resolve requests. These examples use the v4 API; see [migration](/guide/migration-v4) for changes from v3.

## Define routes

```php
use Solo\Router\RouteCollector;

$routes = new RouteCollector();
$routes->get('/users', [UserController::class, 'index']);
$routes->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
$routes->post('/users', [UserController::class, 'store']);
$routes->put('/users/{id}', [UserController::class, 'update']);
$routes->patch('/users/{id}', [UserController::class, 'patch']);
$routes->delete('/users/{id}', [UserController::class, 'destroy']);
```

HEAD requests fall back to GET routes if no explicit HEAD route matches the path. Use `$routes->head()` for a dedicated HEAD handler.

## Configure metadata

Registration returns a mutable `RouteDefinition`. Chain fluent setters:

```php
$routes->get('/profile', [ProfileController::class, 'show'])
    ->name('profile.show')
    ->middleware(AuthMiddleware::class)
    ->attribute('permission', 'profile.view');
```

## Group routes

```php
$routes->group('/api', function (RouteCollector $routes) {
    $routes->get('/posts', [PostController::class, 'index']);
    $routes->get('/posts/{id}', [PostController::class, 'show']);
}, [ApiMiddleware::class], ['area' => 'api']);
```

Prefixes concatenate. Middleware accumulates from outer groups to the route; attributes are replaced by key when an inner group or route overrides them.

## Build once configuration is complete

```php
$router = $routes->build();
```

Building validates path syntax, regexes, duplicate paths and names, and routes that could never match because an earlier route with the same method has the same pattern. The returned `Router` contains runtime snapshots. Later registrations or changes to definitions affect subsequent builds only.

## Resolve a request

```php
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;

$result = $router->resolve('GET', '/users/123');

if ($result instanceof Matched) {
    $handler = $result->route->handler;
    $params = $result->params; // ['id' => '123']
    $middlewares = $result->route->middlewares;
    $name = $result->route->name;
    $attributes = $result->route->attributes;
    // Your application executes middleware and the handler.
} elseif ($result instanceof MethodNotAllowed) {
    http_response_code(405);
    header('Allow: ' . implode(', ', $result->allowedMethods));
} else {
    http_response_code(404);
}
```

Every result is an object, so test its type rather than its truthiness. Pass the URI path without a query string; captured parameters are returned without decoding.

## Next steps

- [Handlers](/guide/handlers) — Dispatching in your application
- [Parameters](/features/parameters) — Constraints and captured values
- [Optional segments](/features/optional-segments) — Advanced patterns
- [Migration from v3](/guide/migration-v4) — Breaking changes and integrations
