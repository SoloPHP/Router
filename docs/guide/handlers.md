# Handlers

All registration methods accept an opaque `mixed` handler, including non-callable objects such as application request handlers. The router stores handlers and returns the selected handler from `resolve()`. Your application instantiates controllers, executes middleware, and sends responses.

The registration examples assume `$routes = new Solo\Router\RouteCollector()`.

## Invokable controllers

```php
class UserController
{
    public function __invoke($id = null)
    {
        return 'User: ' . ($id ?? 'all');
    }
}

$routes->get('/users', UserController::class);
$routes->get('/users/{id}', UserController::class);
```

## Controller methods

```php
$routes->get('/posts', [PostController::class, 'index']);
$routes->get('/posts/{id}', [PostController::class, 'show']);
$routes->post('/posts', [PostController::class, 'store']);
```

## Closures and functions

```php
$routes->get('/about', fn () => 'About page');
$routes->get('/users/{id}', fn ($id) => "User: $id");

function contactHandler(): string
{
    return 'Contact page';
}

$routes->get('/contact', 'contactHandler');
```

Closures and objects work for routers built in memory. The [route cache](/features/caching) stores only plain values, so use class names, `[Class::class, 'method']` arrays, or function names for routes you want to cache.

## Dispatching handlers

The following is an application-level example for controllers without constructor dependencies:

```php
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;

$router = $routes->build();
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

$handler = $result->route->handler;
$params = $result->params;

if (is_callable($handler)) {
    $response = $handler(...$params);
} elseif (is_array($handler)) {
    [$class, $action] = $handler;
    $controller = new $class();
    $response = $controller->$action(...$params);
} elseif (is_string($handler) && class_exists($handler)) {
    $controller = new $handler();
    $response = $controller(...$params);
} else {
    throw new RuntimeException('Unsupported application handler');
}
```

Parameters are associative, so unpacking passes named arguments. Controller parameter names must match the route parameter names. Optional route parameters need corresponding defaults in the handler.

## Dependency injection

If controllers have dependencies, resolve them with your application's container after handling the non-matched results above:

```php
$handler = $result->route->handler;
$params = $result->params;

if (is_array($handler) && is_string($handler[0])) {
    [$class, $action] = $handler;
    $response = $container->get($class)->$action(...$params);
} elseif (is_string($handler) && class_exists($handler)) {
    $response = $container->get($handler)(...$params);
} else {
    $response = $handler(...$params);
}
```

The container and dispatch policy belong to the application. See [middleware](/features/middleware) for combining middleware with dispatch.
