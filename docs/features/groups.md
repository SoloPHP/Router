# Route Groups

The examples configure a collector. Build a router after configuration to resolve requests. Treat the patterns below as independent examples.

```php
use Solo\Router\RouteCollector;

$routes = new RouteCollector();
```

Groups share prefixes, middleware and attributes across multiple routes.

## Basic Groups

```php
$routes->group('/admin', function(RouteCollector $routes) {
    $routes->get('/dashboard', [AdminController::class, 'dashboard']);
    $routes->get('/users', [AdminController::class, 'users']);
    $routes->get('/settings', [AdminController::class, 'settings']);
});

// Registers:
// GET /admin/dashboard
// GET /admin/users
// GET /admin/settings
```

## Groups with Middleware

Pass middleware as the third parameter:

```php
$routes->group('/admin', function(RouteCollector $routes) {
    $routes->get('/dashboard', [AdminController::class, 'dashboard']);
    $routes->get('/users', [AdminController::class, 'users']);
}, [AuthMiddleware::class, AdminMiddleware::class]);

// All routes have AuthMiddleware and AdminMiddleware
```

## Groups with Attributes

Pass [attributes](/features/attributes) as the fourth parameter, or by name:

```php
$routes->group('/billing', function(RouteCollector $routes) {
    $routes->get('/invoices', [InvoiceController::class, 'index']);
    $routes->get('/plans', [PlanController::class, 'index'])
        ->attribute('permission', 'billing.view');
}, attributes: ['permission' => 'billing.manage']);

// /billing/invoices has ['permission' => 'billing.manage']
// /billing/plans has ['permission' => 'billing.view']
```

Unlike middleware, attributes don't accumulate: an inner group or the route itself replaces the value for the same key. See [Inheritance Rules](/features/attributes#inheritance-rules).

---

## Nested Groups

Groups can be nested to any depth:

```php
$routes->group('/api', function(RouteCollector $routes) {
    
    $routes->group('/v1', function(RouteCollector $routes) {
        $routes->get('/users', [ApiV1UserController::class, 'index']);
        $routes->get('/posts', [ApiV1PostController::class, 'index']);
    });
    
    $routes->group('/v2', function(RouteCollector $routes) {
        $routes->get('/users', [ApiV2UserController::class, 'index']);
        $routes->get('/posts', [ApiV2PostController::class, 'index']);
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
$routes->group('/api', function(RouteCollector $routes) {
    
    $routes->group('/admin', function(RouteCollector $routes) {
        $routes->get('/stats', [AdminController::class, 'stats']);
    }, [AdminMiddleware::class]);
    
}, [ApiMiddleware::class, RateLimitMiddleware::class]);

// /api/admin/stats has, in execution order (outermost first):
// - ApiMiddleware
// - RateLimitMiddleware
// - AdminMiddleware
```

---

## Resource-style Groups

Organize CRUD routes:

```php
$routes->group('/users', function(RouteCollector $routes) {
    $routes->get('/', [UserController::class, 'index'])->name('users.index');
    $routes->get('/create', [UserController::class, 'create'])->name('users.create');
    $routes->post('/', [UserController::class, 'store'])->name('users.store');
    $routes->get('/{id}', [UserController::class, 'show'])->name('users.show');
    $routes->get('/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
    $routes->put('/{id}', [UserController::class, 'update'])->name('users.update');
    $routes->delete('/{id}', [UserController::class, 'destroy'])->name('users.destroy');
});
```

## API Versioning

```php
$routes->group('/api', function(RouteCollector $routes) {
    
    // Version 1
    $routes->group('/v1', function(RouteCollector $routes) {
        $routes->get('/users', [V1\UserController::class, 'index']);
    }, [V1DeprecationMiddleware::class]);
    
    // Version 2 (current)
    $routes->group('/v2', function(RouteCollector $routes) {
        $routes->get('/users', [V2\UserController::class, 'index']);
    });
    
}, [ApiAuthMiddleware::class, JsonMiddleware::class]);
```

---

## Optional Group Prefix

Use optional segments in group prefix:

```php
$routes->group('[/{lang:[a-z]{2}}]', function(RouteCollector $routes) {
    $routes->get('/', [HomeController::class, 'index']);
    $routes->get('/about', [PageController::class, 'about']);
    $routes->get('/contact', [PageController::class, 'contact']);
});

// Matches both:
// /, /about, /contact
// /en/, /en/about, /en/contact
```

## Domain-style Groups

Organize by feature area:

```php
// Public routes
$routes->group('', function(RouteCollector $routes) {
    $routes->get('/', [HomeController::class, 'index']);
    $routes->get('/about', [PageController::class, 'about']);
});

// Auth routes
$routes->group('/auth', function(RouteCollector $routes) {
    $routes->get('/login', [AuthController::class, 'showLogin']);
    $routes->post('/login', [AuthController::class, 'login']);
    $routes->post('/logout', [AuthController::class, 'logout']);
}, [GuestMiddleware::class]);

// Dashboard routes  
$routes->group('/dashboard', function(RouteCollector $routes) {
    $routes->get('/', [DashboardController::class, 'index']);
    $routes->get('/profile', [ProfileController::class, 'show']);
    $routes->put('/profile', [ProfileController::class, 'update']);
}, [AuthMiddleware::class]);

// Admin routes
$routes->group('/admin', function(RouteCollector $routes) {
    $routes->get('/', [AdminController::class, 'index']);
    $routes->get('/users', [AdminUserController::class, 'index']);
}, [AuthMiddleware::class, AdminMiddleware::class]);
```

---

## Best Practices

::: tip Middleware Order
Group middleware runs before route-specific middleware:

```php
$routes->group('/api', function($routes) {
    $routes->get('/users', [UserController::class, 'index'])
        ->middleware(CacheMiddleware::class);
}, [AuthMiddleware::class]);

// Middleware order: AuthMiddleware → CacheMiddleware
```
:::

::: tip Keep Groups Focused
Each group should have a clear purpose (API version, feature area, auth state).
:::
