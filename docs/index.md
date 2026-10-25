---
layout: home

hero:
  name: Solo Router
  text: High-Performance PHP Router
  tagline: Fluent definitions, immutable runtime routes, typed results, and advanced optional segment patterns.
  image:
    src: /logo.svg
    alt: Solo Router
  actions:
    - theme: brand
      text: Get Started
      link: /guide/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/solophp/router

features:
  - icon: ⚡
    title: High Performance
    details: Dynamic routes of each method compile into one regex shaped as a prefix tree, so lookup time barely grows with the route count. A route cache loads prebuilt routers from OPcache.
  - icon: 🔗
    title: Fluent API
    details: Chainable ->name(), ->middleware() and ->attribute() methods for clean route definitions.
  - icon: 📦
    title: Route Groups
    details: Shared prefixes, middleware and attributes with nested group support.
  - icon: 🛡️
    title: Middleware
    details: Single and multiple middleware per route or group.
  - icon: 🎯
    title: Advanced Patterns
    details: Optional segments in any position, nested optionals, regex constraints.
  - icon: 🏷️
    title: Named Routes
    details: Retrieve immutable runtime routes by their final names.
  - icon: 🧩
    title: Route Attributes
    details: Custom route data such as permissions, available on typed match results.
  - icon: 📡
    title: HEAD Support
    details: Automatic fallback to GET routes for HEAD requests per RFC 9110.
---

<style>
:root {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #f59e0b30 0%, #ef444430 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

## Version 4.0.0

Solo Router 4.0.0 separates route configuration from request resolution. The core requires PHP 8.3 or later, with no external runtime packages. See [installation](/guide/installation), [migration from v3](/guide/migration-v4), and [performance measurements](/guide/performance).

## Quick Example

```php
use Solo\Router\RouteCollector;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;

$routes = new RouteCollector();
$routes->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show')
    ->middleware(AuthMiddleware::class);

$router = $routes->build();
$result = $router->resolve('GET', '/users/123');

if ($result instanceof Matched) {
    $handler = $result->route->handler;
    $params = $result->params; // ['id' => '123']
    $middlewares = $result->route->middlewares;
} elseif ($result instanceof MethodNotAllowed) {
    http_response_code(405);
    header('Allow: ' . implode(', ', $result->allowedMethods));
} else {
    http_response_code(404);
}
```

Configuration changes affect subsequent builds only. The router returns metadata; your application executes middleware and handlers.

```bash
composer require "solophp/router:^4.0"
```

**Requirements:** PHP 8.3 or later and Composer.

[Get started](/guide/quick-start) · [Runtime API](/api/router) · [Migration guide](/guide/migration-v4)
