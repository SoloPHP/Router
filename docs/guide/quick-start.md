# Quick Start

## HTTP Methods

Register routes for different HTTP methods:

```php
use Solo\Router\RouteCollector;

$router = new RouteCollector();

$router->get('/users', [UserController::class, 'index']);
$router->post('/users', [UserController::class, 'store']);
$router->put('/users/{id}', [UserController::class, 'update']);
$router->patch('/users/{id}', [UserController::class, 'patch']);
$router->delete('/users/{id}', [UserController::class, 'destroy']);
```

## Route Parameters

Capture dynamic segments with curly braces:

```php
// Required parameter
$router->get('/users/{id}', [UserController::class, 'show']);

// Multiple parameters
$router->get('/posts/{postId}/comments/{commentId}', [CommentController::class, 'show']);
```

## Matching Requests

Use `match()` to find a route for a request:

```php
$match = $router->match('GET', '/users/123');

if ($match) {
    $handler = $match['handler'];     // [UserController::class, 'show']
    $params = $match['params'];       // ['id' => '123']
    $middlewares = $match['middlewares'];
} else {
    // No matching route found
}
```

## Fluent Configuration

Chain `name()` and `middleware()` calls:

```php
$router->get('/profile', [ProfileController::class, 'show'])
    ->name('profile.show')
    ->middleware(AuthMiddleware::class);

$router->post('/posts', [PostController::class, 'store'])
    ->name('posts.store')
    ->middleware(AuthMiddleware::class, ThrottleMiddleware::class);
```

## Route Groups

Group routes with shared prefix and middleware:

```php
$router->group('/api', function(RouteCollector $router) {
    $router->get('/users', [ApiUserController::class, 'index']);
    $router->get('/posts', [ApiPostController::class, 'index']);
}, [ApiMiddleware::class]);

// Matches: /api/users, /api/posts
```

## Complete Example

```php
use Solo\Router\RouteCollector;

$router = new RouteCollector();

// Public routes
$router->get('/', [HomeController::class, 'index'])->name('home');
$router->get('/about', [PageController::class, 'about'])->name('about');

// Auth routes
$router->post('/login', [AuthController::class, 'login'])->name('login');
$router->post('/logout', [AuthController::class, 'logout'])
    ->name('logout')
    ->middleware(AuthMiddleware::class);

// API routes
$router->group('/api/v1', function(RouteCollector $router) {
    $router->get('/users', [UserController::class, 'index']);
    $router->get('/users/{id}', [UserController::class, 'show']);
    $router->post('/users', [UserController::class, 'store']);
}, [ApiAuthMiddleware::class, JsonMiddleware::class]);

// Admin routes
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    
    $router->group('/users', function(RouteCollector $router) {
        $router->get('/', [AdminUserController::class, 'index']);
        $router->get('/{id}/edit', [AdminUserController::class, 'edit']);
    });
}, [AuthMiddleware::class, AdminMiddleware::class]);
```

## Next Steps

- [Handlers](/guide/handlers) — Different handler types
- [Route Parameters](/features/parameters) — Parameters with patterns
- [Optional Segments](/features/optional-segments) — Advanced patterns
