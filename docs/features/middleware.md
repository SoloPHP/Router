# Middleware

Routes store middleware metadata. Your application executes the middleware around a matched handler; the router itself does not dispatch requests.

The registration examples assume `$routes = new Solo\Router\RouteCollector()`.

## Adding middleware

```php
$routes->get('/profile', [ProfileController::class, 'show'])
    ->middleware(AuthMiddleware::class);

$routes->get('/admin/users', [AdminController::class, 'users'])
    ->middleware(AuthMiddleware::class, AdminMiddleware::class, LogMiddleware::class);
```

Group middleware is passed as the third argument:

```php
use Solo\Router\RouteCollector;

$routes->group('/admin', function (RouteCollector $routes) {
    $routes->get('/dashboard', [AdminController::class, 'dashboard']);
}, [AuthMiddleware::class, AdminMiddleware::class]);
```

## Order

Middleware is stored in outer-to-inner order:

1. Outermost group middleware
2. Nested group middleware
3. Route middleware in the order appended

```php
$routes->group('/api', function (RouteCollector $routes) {
    $routes->group('/admin', function (RouteCollector $routes) {
        $routes->get('/stats', [StatsController::class, 'index'])
            ->middleware(CacheMiddleware::class);
    }, [AdminMiddleware::class]);
}, [ApiMiddleware::class]);

// ApiMiddleware → AdminMiddleware → CacheMiddleware → handler
```

## Accessing middleware

```php
use Solo\Router\Results\Matched;

$router = $routes->build();
$result = $router->resolve('GET', '/admin/dashboard');

if ($result instanceof Matched) {
    $middlewares = $result->route->middlewares;
    // [AuthMiddleware::class, AdminMiddleware::class]
}
```

Fluent changes to definitions after a build affect later builds only.

## Application pipeline example

This example assumes the application supplies `$container`, `$request`, and a `$dispatch($handler, $params, $request)` callable. Its middleware objects expose `handle($request, $next)`, and middleware closures have the same arguments. These are example application conventions, not interfaces required by Solo Router.

```php
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;

$result = $router->resolve($method, $uri);
if (!$result instanceof Matched) {
    if ($result instanceof MethodNotAllowed) {
        http_response_code(405);
        header('Allow: ' . implode(', ', $result->allowedMethods));
    } else {
        http_response_code(404);
    }
    return;
}

$pipeline = static fn ($request) => $dispatch(
    $result->route->handler,
    $result->params,
    $request
);

foreach (array_reverse($result->route->middlewares) as $middleware) {
    $next = $pipeline;
    $pipeline = static function ($request) use ($middleware, $next, $container) {
        if (is_string($middleware) && class_exists($middleware)) {
            return $container->get($middleware)->handle($request, $next);
        }
        return $middleware($request, $next);
    };
}

$response = $pipeline($request);
```

Reversing the stored list while constructing the pipeline makes outer group middleware execute first and wrap the remaining middleware.

## Closure middleware

```php
$routes->get('/profile', [ProfileController::class, 'show'])
    ->middleware(function ($request, $next) {
        // Perform an application check here.
        return $next($request);
    });
```

Build the router after adding this definition to include it in the runtime snapshot.
