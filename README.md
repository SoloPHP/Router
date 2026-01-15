# Solo Router

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/router.svg)](https://packagist.org/packages/solophp/router)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/router.svg)](https://packagist.org/packages/solophp/router)
[![License](https://img.shields.io/packagist/l/solophp/router.svg)](https://github.com/solophp/router/blob/main/LICENSE)

High-performance PHP router with middleware support, route groups, named routes, and advanced optional segment patterns.

📖 **[Full Documentation](https://solophp.github.io/Router/)**

## ✨ Features

- ⚡ **High Performance** — Static routes use O(1) hash lookup, pattern caching
- 🔗 **Fluent API** — Chainable `->name()` and `->middleware()` methods
- 📦 **Route Groups** — Shared prefixes and middleware with nesting
- 🛡️ **Middleware** — Single and multiple middleware per route/group
- 🎯 **Advanced Patterns** — Optional segments anywhere, nested optionals, regex constraints
- 🏷️ **Named Routes** — Easy route referencing for URL generation

## 📦 Installation

```bash
composer require solophp/router
```

## 🚀 Quick Example

```php
use Solo\Router\RouteCollector;

$router = new RouteCollector();

// Simple routes
$router->get('/users', [UserController::class, 'index']);
$router->get('/users/{id}', [UserController::class, 'show']);

// Route with middleware and name
$router->post('/posts', [PostController::class, 'store'])
    ->middleware(AuthMiddleware::class)
    ->name('posts.store');

// Route groups
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
}, [AuthMiddleware::class]);

// Match request
$match = $router->match('GET', '/users/123');
if ($match) {
    $handler = $match['handler'];
    $params = $match['params'];       // ['id' => '123']
    $middlewares = $match['middlewares'];
}
```

## 📋 Requirements

- PHP 8.1+
- Composer

## 📄 License

MIT License. See [LICENSE](LICENSE) for details.
