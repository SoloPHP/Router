# Installation

## Requirements

- PHP 8.3 or later within PHP 8.x (`^8.3`)
- Composer

Projects on PHP 8.1 or 8.2 can stay on version 3.5.

## Composer

Install Solo Router 4:

```bash
composer require "solophp/router:^4.0"
```

These pages document version 4.0.0. When upgrading an existing application, follow the [v3 to v4 migration guide](/guide/migration-v4).

## Dependencies and integrations

The core package requires only PHP. It has no dependency on `solophp/contracts` and implements neither of its v3 router interfaces. Integrations should use the v4 builder and typed resolution API; see [migration](/guide/migration-v4#contracts-and-integrations).

## Basic setup

```php
use Solo\Router\RouteCollector;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;

$routes = new RouteCollector();
$routes->get('/', [HomeController::class, 'index']);
$routes->get('/about', [PageController::class, 'about']);
$router = $routes->build();

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($uri)) {
    http_response_code(400);
    return;
}

$result = $router->resolve($method, $uri);
if ($result instanceof Matched) {
    $handler = $result->route->handler;
    $params = $result->params;
    // Dispatch with your application's controller and middleware integration.
} elseif ($result instanceof MethodNotAllowed) {
    http_response_code(405);
    header('Allow: ' . implode(', ', $result->allowedMethods));
} else {
    http_response_code(404);
}
```

## Next steps

- [Quick start](/guide/quick-start)
- [Handlers](/guide/handlers)
- [Migrating from v3](/guide/migration-v4)
