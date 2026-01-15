# Handlers

The router supports three types of handlers.

## Invokable Controllers

Classes with `__invoke()` method:

```php
class UserController
{
    public function __invoke($id = null)
    {
        return "User: " . ($id ?? 'all');
    }
}

// Pass the class name directly
$router->get('/users', UserController::class);
$router->get('/users/{id}', UserController::class);
```

## Controller Methods

Classes with specific methods:

```php
class PostController
{
    public function index()
    {
        return "All posts";
    }
    
    public function show($id)
    {
        return "Post: $id";
    }
    
    public function store()
    {
        return "Create post";
    }
}

// Pass [ClassName::class, 'methodName']
$router->get('/posts', [PostController::class, 'index']);
$router->get('/posts/{id}', [PostController::class, 'show']);
$router->post('/posts', [PostController::class, 'store']);
```

## Closures and Functions

Direct callable handlers:

```php
// Closure
$router->get('/about', function() {
    return "About page";
});

// Closure with parameters
$router->get('/users/{id}', function($id) {
    return "User: $id";
});

// Named function
function contactHandler() {
    return "Contact page";
}

$router->get('/contact', 'contactHandler');
```

---

## Dispatching Handlers

After matching a route, dispatch the handler:

```php
$match = $router->match($method, $uri);

if ($match === false) {
    http_response_code(404);
    exit;
}

$handler = $match['handler'];
$params = $match['params'];

// Dispatch based on handler type
if (is_callable($handler)) {
    // Closure or function
    $response = call_user_func_array($handler, $params);
    
} elseif (is_array($handler)) {
    // [ClassName::class, 'method']
    [$class, $method] = $handler;
    $controller = new $class();
    $response = call_user_func_array([$controller, $method], $params);
    
} elseif (is_string($handler) && class_exists($handler)) {
    // Invokable class
    $controller = new $handler();
    $response = call_user_func_array($controller, $params);
}
```

## With Dependency Injection

Using a DI container:

```php
$match = $router->match($method, $uri);

if ($match === false) {
    return $this->notFound();
}

$handler = $match['handler'];
$params = $match['params'];

if (is_array($handler)) {
    [$class, $method] = $handler;
    $controller = $container->get($class);
    return $controller->$method(...$params);
}

if (is_string($handler) && class_exists($handler)) {
    $controller = $container->get($handler);
    return $controller(...$params);
}

return $handler(...$params);
```

## With Middleware

Process middleware before dispatching:

```php
$match = $router->match($method, $uri);

if ($match === false) {
    return $this->notFound();
}

// Run middleware chain
$middlewares = $match['middlewares'];
foreach ($middlewares as $middleware) {
    $middlewareInstance = $container->get($middleware);
    $response = $middlewareInstance->handle($request, function($req) {
        return null; // Continue
    });
    
    if ($response !== null) {
        return $response; // Middleware returned response
    }
}

// Dispatch handler
$handler = $match['handler'];
$params = $match['params'];
// ... dispatch as shown above
```
