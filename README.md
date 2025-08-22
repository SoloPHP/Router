# Solo Router

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/router.svg)](https://packagist.org/packages/solophp/router)
[![License](https://img.shields.io/packagist/l/solophp/router.svg)](https://github.com/solophp/router/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/router.svg)](https://packagist.org/packages/solophp/router)

A lightweight and flexible PHP router with middleware support, route groups, and named routes.

## Requirements

- PHP 8.1 or higher
- Composer

## Installation

You can install the package via composer:

```bash
composer require solophp/router
```

## Basic Usage

```php
use Solo\Router\RouteCollector;

$router = new RouteCollector();

// Invokable controller
$router->get('/users', UserController::class);

// Traditional controller with method
$router->get('/users', [UserController::class, 'index']);

// Named routes (via argument)
$router->get('/users/{id}', [UserController::class, 'show'], [], 'users.show');

// Optional parameters
$router->get('/posts[/{page}]', [PostController::class, 'index']);

// With middleware and name
$router->post('/admin/posts', [PostController::class, 'store'], [AuthMiddleware::class], 'admin.posts');

// Route groups
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
}, [AuthMiddleware::class]);

// Match route
$match = $router->matchRoute('GET', '/users/123');
if ($match) {
    $route = $match->route; // instance of Solo\Router\Route
    $handler = $route->handler;
    $args = $match->args; // ['id' => '123']
    $middlewares = $route->middlewares;
    $name = $route->name; // 'users.show' or null
}
```

## Features

### HTTP Methods Support
- GET
- POST
- PUT
- PATCH
- DELETE

### Route Parameters

```php
// Required parameters
$router->get('/users/{id}', [UserController::class, 'show']);

// Optional parameters
$router->get('/posts[/{category}[/{page}]]', [PostController::class, 'index']);

// Parameters with regex patterns
$router->get('/users/{id:[0-9]+}', [UserController::class, 'show']);
$router->get('/articles/{slug:[a-z0-9-]+}', [ArticleController::class, 'show']);
```

### Invokable Controllers

Router supports invokable controllers that implement `__invoke` method:

```php
$router->get('/users', UserController::class);
$router->post('/users', UserCreateController::class);
```

### Named Routes

```php
$router->get('/users/{id}', [UserController::class, 'show'], [], 'users.show');
```

### Route Groups

Route groups allow you to share route attributes like prefixes and middleware:

```php
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard'], [], 'admin.dashboard');
    $router->get('/settings', [AdminController::class, 'settings'], [], 'admin.settings');
}, [AdminMiddleware::class]);
```



### Middleware Support

```php
// Single middleware
$router->get('/profile', [ProfileController::class, 'show'], [AuthMiddleware::class]);

// Multiple middleware
$router->get('/admin/settings', [SettingsController::class, 'show'], [
    AuthMiddleware::class,
    AdminMiddleware::class
]);

// Group middleware
$router->group('/api', function(RouteCollector $router) {
    $router->get('/users', [ApiController::class, 'users']);
}, [ApiAuthMiddleware::class]);
```

### Route Information

When a route is matched, it returns a `MatchResult` object with:
- `route` — instance of `Solo\Router\Route` with readonly properties: `method`, `group`, `path`, `handler`, `middlewares`, `name`
- `args` — associative array of route parameters

## Error Handling

The router throws `InvalidArgumentException` in the following cases:
- When adding a route with an unsupported HTTP method
- When trying to use a route name that already exists

## Testing

```bash
# Run tests
composer test

# Run code style check
composer cs

# Fix code style issues
composer cs-fix
```

## License

This package is open-sourced software licensed under the MIT license.
