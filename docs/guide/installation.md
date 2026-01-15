# Installation

## Requirements

- PHP 8.1 or higher
- Composer

## Composer

Install the package via Composer:

```bash
composer require solophp/router
```

## Dependencies

```json
{
    "require": {
        "php": ">=8.1",
        "solophp/contracts": "^1.2"
    }
}
```

## Basic Setup

```php
use Solo\Router\RouteCollector;

$router = new RouteCollector();

// Define routes
$router->get('/', [HomeController::class, 'index']);
$router->get('/about', [PageController::class, 'about']);

// Match incoming request
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$match = $router->match($method, $uri);

if ($match) {
    // Route found
    $handler = $match['handler'];
    $params = $match['params'];
    $middlewares = $match['middlewares'];
} else {
    // 404 Not Found
    http_response_code(404);
}
```

## Next Steps

- [Quick Start](/guide/quick-start) — Define your first routes
- [Handlers](/guide/handlers) — Learn about handler types
