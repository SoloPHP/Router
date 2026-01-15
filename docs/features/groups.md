# Route Groups

Groups share prefixes and middleware across multiple routes.

## Basic Groups

```php
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
    $router->get('/settings', [AdminController::class, 'settings']);
});

// Registers:
// GET /admin/dashboard
// GET /admin/users
// GET /admin/settings
```

## Groups with Middleware

Pass middleware as the third parameter:

```php
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
}, [AuthMiddleware::class, AdminMiddleware::class]);

// All routes have AuthMiddleware and AdminMiddleware
```

---

## Nested Groups

Groups can be nested to any depth:

```php
$router->group('/api', function(RouteCollector $router) {
    
    $router->group('/v1', function(RouteCollector $router) {
        $router->get('/users', [ApiV1UserController::class, 'index']);
        $router->get('/posts', [ApiV1PostController::class, 'index']);
    });
    
    $router->group('/v2', function(RouteCollector $router) {
        $router->get('/users', [ApiV2UserController::class, 'index']);
        $router->get('/posts', [ApiV2PostController::class, 'index']);
    });
    
});

// Registers:
// GET /api/v1/users
// GET /api/v1/posts
// GET /api/v2/users
// GET /api/v2/posts
```

## Nested Middleware

Middleware is inherited and merged:

```php
$router->group('/api', function(RouteCollector $router) {
    
    $router->group('/admin', function(RouteCollector $router) {
        $router->get('/stats', [AdminController::class, 'stats']);
    }, [AdminMiddleware::class]);
    
}, [ApiMiddleware::class, RateLimitMiddleware::class]);

// /api/admin/stats has:
// - AdminMiddleware
// - ApiMiddleware  
// - RateLimitMiddleware
```

---

## Resource-style Groups

Organize CRUD routes:

```php
$router->group('/users', function(RouteCollector $router) {
    $router->get('/', [UserController::class, 'index'])->name('users.index');
    $router->get('/create', [UserController::class, 'create'])->name('users.create');
    $router->post('/', [UserController::class, 'store'])->name('users.store');
    $router->get('/{id}', [UserController::class, 'show'])->name('users.show');
    $router->get('/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
    $router->put('/{id}', [UserController::class, 'update'])->name('users.update');
    $router->delete('/{id}', [UserController::class, 'destroy'])->name('users.destroy');
});
```

## API Versioning

```php
$router->group('/api', function(RouteCollector $router) {
    
    // Version 1
    $router->group('/v1', function(RouteCollector $router) {
        $router->get('/users', [V1\UserController::class, 'index']);
    }, [V1DeprecationMiddleware::class]);
    
    // Version 2 (current)
    $router->group('/v2', function(RouteCollector $router) {
        $router->get('/users', [V2\UserController::class, 'index']);
    });
    
}, [ApiAuthMiddleware::class, JsonMiddleware::class]);
```

---

## Optional Group Prefix

Use optional segments in group prefix:

```php
$router->group('[/{lang:[a-z]{2}}]', function(RouteCollector $router) {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/about', [PageController::class, 'about']);
    $router->get('/contact', [PageController::class, 'contact']);
});

// Matches both:
// /, /about, /contact
// /en, /en/about, /en/contact
```

## Domain-style Groups

Organize by feature area:

```php
// Public routes
$router->group('', function(RouteCollector $router) {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/about', [PageController::class, 'about']);
});

// Auth routes
$router->group('/auth', function(RouteCollector $router) {
    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->post('/logout', [AuthController::class, 'logout']);
}, [GuestMiddleware::class]);

// Dashboard routes  
$router->group('/dashboard', function(RouteCollector $router) {
    $router->get('/', [DashboardController::class, 'index']);
    $router->get('/profile', [ProfileController::class, 'show']);
    $router->put('/profile', [ProfileController::class, 'update']);
}, [AuthMiddleware::class]);

// Admin routes
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/', [AdminController::class, 'index']);
    $router->get('/users', [AdminUserController::class, 'index']);
}, [AuthMiddleware::class, AdminMiddleware::class]);
```

---

## Best Practices

::: tip Middleware Order
Group middleware runs before route-specific middleware:

```php
$router->group('/api', function($router) {
    $router->get('/users', [UserController::class, 'index'])
        ->middleware(CacheMiddleware::class);
}, [AuthMiddleware::class]);

// Middleware order: AuthMiddleware → CacheMiddleware
```
:::

::: tip Keep Groups Focused
Each group should have a clear purpose (API version, feature area, auth state).
:::
