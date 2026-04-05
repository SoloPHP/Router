# RouteCollector

The main class for defining routes with a fluent API.

```php
use Solo\Router\RouteCollector;

$router = new RouteCollector();
```

## HTTP Method Shortcuts

### get()

```php
public function get(string $path, callable|array|string $handler): Route
```

Register a GET route.

```php
$router->get('/users', [UserController::class, 'index']);
$router->get('/users/{id}', [UserController::class, 'show']);
```

### head()

```php
public function head(string $path, callable|array|string $handler): Route
```

Register a HEAD route. Usually not needed — HEAD requests automatically fall back to GET routes per RFC 7231.

```php
// Explicit HEAD route (optional — GET routes handle HEAD automatically)
$router->head('/status', [StatusController::class, 'head']);
```

### post()

```php
public function post(string $path, callable|array|string $handler): Route
```

Register a POST route.

```php
$router->post('/users', [UserController::class, 'store']);
```

### put()

```php
public function put(string $path, callable|array|string $handler): Route
```

Register a PUT route.

```php
$router->put('/users/{id}', [UserController::class, 'update']);
```

### patch()

```php
public function patch(string $path, callable|array|string $handler): Route
```

Register a PATCH route.

```php
$router->patch('/users/{id}', [UserController::class, 'patch']);
```

### delete()

```php
public function delete(string $path, callable|array|string $handler): Route
```

Register a DELETE route.

```php
$router->delete('/users/{id}', [UserController::class, 'destroy']);
```

---

## group()

```php
public function group(
    string $prefix,
    callable $callback,
    array $middlewares = []
): void
```

Create a route group with shared prefix and middleware.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$prefix` | `string` | URL prefix for all routes in the group |
| `$callback` | `callable` | Function receiving RouteCollector instance |
| `$middlewares` | `array` | Middleware to apply to all routes |

**Example:**

```php
$router->group('/api/v1', function(RouteCollector $router) {
    $router->get('/users', [UserController::class, 'index']);
    $router->get('/posts', [PostController::class, 'index']);
}, [ApiMiddleware::class, AuthMiddleware::class]);
```

**Nested groups:**

```php
$router->group('/api', function(RouteCollector $router) {
    $router->group('/v1', function(RouteCollector $router) {
        $router->get('/users', [V1UserController::class, 'index']);
    });
    
    $router->group('/v2', function(RouteCollector $router) {
        $router->get('/users', [V2UserController::class, 'index']);
    });
});
```

---

## Inherited from Router

RouteCollector extends Router, so all Router methods are available:

- [`match()`](/api/router#match)
- [`getRoutes()`](/api/router#getroutes)
- [`getRouteByName()`](/api/router#getroutebyname)
- [`addRoute()`](/api/router#addroute)

---

## Complete Example

```php
use Solo\Router\RouteCollector;

$router = new RouteCollector();

// Public routes
$router->get('/', [HomeController::class, 'index'])->name('home');
$router->get('/about', [PageController::class, 'about'])->name('about');

// Auth routes
$router->group('/auth', function(RouteCollector $router) {
    $router->get('/login', [AuthController::class, 'showLogin'])->name('login');
    $router->post('/login', [AuthController::class, 'login'])->name('login.submit');
    $router->post('/logout', [AuthController::class, 'logout'])->name('logout');
});

// API routes
$router->group('/api', function(RouteCollector $router) {
    $router->group('/v1', function(RouteCollector $router) {
        $router->get('/users', [ApiUserController::class, 'index']);
        $router->get('/users/{id}', [ApiUserController::class, 'show']);
        $router->post('/users', [ApiUserController::class, 'store']);
        $router->put('/users/{id}', [ApiUserController::class, 'update']);
        $router->delete('/users/{id}', [ApiUserController::class, 'destroy']);
    }, [RateLimitMiddleware::class]);
}, [ApiAuthMiddleware::class, JsonMiddleware::class]);

// Match request
$match = $router->match($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
```
