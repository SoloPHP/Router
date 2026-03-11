---
layout: home

hero:
  name: Solo Router
  text: High-Performance PHP Router
  tagline: Middleware support, route groups, named routes, and advanced optional segment patterns.
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
    details: Static routes use O(1) hash lookup. Pattern caching for compiled regex.
  - icon: 🔗
    title: Fluent API
    details: Chainable ->name() and ->middleware() methods for clean route definitions.
  - icon: 📦
    title: Route Groups
    details: Shared prefixes and middleware with nested group support.
  - icon: 🛡️
    title: Middleware
    details: Single and multiple middleware per route or group.
  - icon: 🎯
    title: Advanced Patterns
    details: Optional segments in any position, nested optionals, regex constraints.
  - icon: 🏷️
    title: Named Routes
    details: Easy route referencing by name for URL generation.
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

## Installation

```bash
composer require solophp/router
```

**Requirements:** PHP 8.1+
