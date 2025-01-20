# Solo Router

[![Latest Version](https://img.shields.io/badge/version-1.1.0-blue.svg)](https://github.com/solophp/application/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

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

// Add simple routes
$router->get('/users', [UserController::class, 'index']);
$router->post('/users', [UserController::class, 'store']);

// Named routes
$router->get('/users/{id}', [UserController::class, 'show'])->name('users.show');

// Optional parameters
$router->get('/posts[/{page}]', [PostController::class, 'index']);

// With middleware and page attribute
$router->post('/admin/posts', [PostController::class, 'store'], 
    [AuthMiddleware::class], 
    'admin.posts'
);

// Route groups
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
}, [AuthMiddleware::class]);

// Match route
$route = $router->matchRoute('GET', '/users/123');
if ($route) {
    // Handle the route
    $handler = $route['handler'];
    $args = $route['args']; // ['id' => '123']
    $middleware = $route['middleware'];
    $page = $route['page']; // Optional page identifier
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

### Named Routes

```php
$router->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show');
```

### Route Groups

Route groups allow you to share route attributes like prefixes and middleware:

```php
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard'], [], 'admin.dashboard');
    $router->get('/settings', [AdminController::class, 'settings'], [], 'admin.settings');
}, [AdminMiddleware::class]);
```

### Page Attributes

Routes can have an optional page attribute for identifying specific pages or sections:

```php
// Simple page attribute
$router->get('/about', [PageController::class, 'about'], [], 'about');

// With middleware and page
$router->get('/profile', [ProfileController::class, 'show'], 
    [AuthMiddleware::class], 
    'user.profile'
);

// In groups
$router->group('/blog', function(RouteCollector $router) {
    $router->get('/', [BlogController::class, 'index'], [], 'blog.index');
    $router->get('/{slug}', [BlogController::class, 'show'], [], 'blog.show');
});
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

When a route is matched, it returns an array containing:
- `method` - HTTP method
- `group` - Route group prefix
- `handler` - Route handler (callable or controller array)
- `args` - Route parameters
- `middleware` - Optional Array of middleware
- `page` - Optional page identifier

## Error Handling

The router throws `InvalidArgumentException` in the following cases:
- When adding a route with an unsupported HTTP method
- When trying to name a route before adding any routes
- When trying to use a route name that already exists

## License

This package is open-sourced software licensed under the MIT license.
