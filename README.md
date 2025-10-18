# Solo Router

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/router.svg)](https://packagist.org/packages/solophp/router)
[![License](https://img.shields.io/packagist/l/solophp/router.svg)](https://github.com/solophp/router/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/router.svg)](https://packagist.org/packages/solophp/router)

A high-performance PHP router with middleware support, route groups, named routes, and advanced optional segment patterns.

## Key Features

- **HTTP Methods**: GET, POST, PUT, PATCH, DELETE
- **Route Parameters**: Required and optional with regex patterns
- **Advanced Optional Segments**: Support for complex patterns including middle segments, nested segments, and multiple optional parts
- **Route Groups**: Shared prefixes and middleware
- **Named Routes**: Easy route referencing
- **Middleware Support**: Single and multiple middleware per route/group
- **Flexible Handlers**: Functions, closures, invokable classes, and controller methods
- **Pattern Caching**: Compiled regex patterns for better performance
- **Type Safety**: Full PHP 8.1+ type declarations and static analysis support

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

// Simple routes
$router->get('/users', [UserController::class, 'index']);
$router->get('/users/{id}', [UserController::class, 'show']);

// Route with middleware
$router->post('/posts', [PostController::class, 'store'], [AuthMiddleware::class]);

// Route groups
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
}, [AuthMiddleware::class]);

// Match routes
$match = $router->match('GET', '/users/123');
if ($match) {
    $handler = $match['handler'];
    $params = $match['params']; // ['id' => '123']
    $middlewares = $match['middlewares'];
}
```

## Route Parameters

```php
// Required parameters
$router->get('/users/{id}', [UserController::class, 'show']);

// Optional parameters
$router->get('/posts[/{page}]', [PostController::class, 'index']);

// Parameters with regex patterns
$router->get('/users/{id:[0-9]+}', [UserController::class, 'show']);
$router->get('/articles/{slug:[a-z0-9-]+}', [ArticleController::class, 'show']);
```

## Optional Segments

The router supports flexible optional segment patterns:

```php
// Optional segments in the middle
$router->get('/users[/{id}]/posts', [PostController::class, 'index']);
// Matches: /users/posts, /users/123/posts

// Multiple optional segments
$router->get('/api[/v{version}]/users[/{id}]', [ApiController::class, 'users']);
// Matches: /api/users, /api/v2/users, /api/v2/users/123

// Nested optional segments
$router->get('/shop[/category/{cat}[/subcategory/{subcat}]]', [ShopController::class, 'index']);
// Matches: /shop, /shop/category/electronics, /shop/category/electronics/subcategory/phones
```

## Route Groups

```php
// Group with prefix and middleware
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
}, [AuthMiddleware::class]);

// Nested groups
$router->group('/api', function(RouteCollector $router) {
    $router->group('/v1', function(RouteCollector $router) {
        $router->get('/users', [ApiController::class, 'users']);
    });
});
```

## Middleware

```php
// Single middleware
$router->get('/profile', [ProfileController::class, 'show'], [AuthMiddleware::class]);

// Multiple middleware
$router->get('/admin/settings', [SettingsController::class, 'show'], [
    AuthMiddleware::class,
    AdminMiddleware::class
]);
```

## Named Routes

```php
$router->get('/users/{id}', [UserController::class, 'show'], [], 'users.show');

// Get route by name
$route = $router->getRouteByName('users.show');
```

## Controller Types

The router supports three types of handlers:

### 1. Invokable Controllers
Classes that implement the `__invoke()` method:

```php
class UserController
{
    public function __invoke($id = null)
    {
        return "User: " . ($id ?? 'all');
    }
}

// Just pass the class name
$router->get('/users', UserController::class);
$router->get('/users/{id}', UserController::class);
```

### 2. Regular Controllers
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
}

// Pass [ClassName::class, 'methodName']
$router->get('/posts', [PostController::class, 'index']);
$router->get('/posts/{id}', [PostController::class, 'show']);
```

### 3. Functions and Closures
Direct callable functions:

```php
$router->get('/about', function() {
    return "About page";
});

$router->get('/contact', 'contact_handler');
```

## Error Handling

The router throws `InvalidArgumentException` in the following cases:
- When adding a route with an unsupported HTTP method
- When trying to use a route name that already exists
- When route patterns contain unmatched brackets (e.g., `{id` or `id}`)

## Testing

```bash
# Run tests
composer test

# Run static analysis
composer analyze

# Run code style check
composer cs

# Fix code style issues
composer cs-fix
```

## License

This package is open-sourced software licensed under the MIT license.
