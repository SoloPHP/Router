# Solo Router

High-performance PHP router with middleware support, route groups, named routes, and advanced optional segment patterns.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/router.svg)](https://packagist.org/packages/solophp/router)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- **High Performance** — Static routes use O(1) hash lookup, pattern caching
- **Fluent API** — Chainable `->name()` and `->middleware()` methods
- **Route Groups** — Shared prefixes and middleware with nesting
- **Middleware** — Single and multiple middleware per route/group
- **Advanced Patterns** — Optional segments anywhere, nested optionals, regex constraints
- **Named Routes** — Easy route referencing for URL generation
- **HEAD Support** — Automatic fallback to GET routes per RFC 7231

## Installation

```bash
composer require solophp/router
```

## Quick Example

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
    $name = $match['name'];           // route name or null
}
```

## Documentation

**[Full Documentation](https://solophp.github.io/Router/)**

- [Installation](https://solophp.github.io/Router/guide/installation)
- [Quick Start](https://solophp.github.io/Router/guide/quick-start)
- [Handlers](https://solophp.github.io/Router/guide/handlers)
- [Route Parameters](https://solophp.github.io/Router/features/parameters)
- [Optional Segments](https://solophp.github.io/Router/features/optional-segments)
- [Route Groups](https://solophp.github.io/Router/features/groups)
- [Middleware](https://solophp.github.io/Router/features/middleware)
- [Named Routes](https://solophp.github.io/Router/features/named-routes)
- [API Reference](https://solophp.github.io/Router/api/router)

## Requirements

- PHP 8.1+

## License

MIT License. See [LICENSE](LICENSE) for details.
