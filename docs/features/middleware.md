# Middleware

Middleware intercepts requests before they reach the handler.

## Adding Middleware

### Single Middleware

```php
$router->get('/profile', [ProfileController::class, 'show'])
    ->middleware(AuthMiddleware::class);
```

### Multiple Middleware

```php
$router->get('/admin/users', [AdminController::class, 'users'])
    ->middleware(AuthMiddleware::class, AdminMiddleware::class, LogMiddleware::class);
```

### Group Middleware

Pass middleware array to `group()`:

```php
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
}, [AuthMiddleware::class, AdminMiddleware::class]);
```

---

## Middleware Execution Order

1. **Group middleware** (outermost group first)
2. **Route middleware** (in order added)

```php
$router->group('/api', function($router) {
    $router->group('/admin', function($router) {
        $router->get('/stats', [StatsController::class, 'index'])
            ->middleware(CacheMiddleware::class);
    }, [AdminMiddleware::class]);
}, [ApiMiddleware::class]);

// Order: ApiMiddleware → AdminMiddleware → CacheMiddleware
```

---

## Accessing Middleware

Middleware is returned in the match result:

```php
$match = $router->match('GET', '/admin/dashboard');

if ($match) {
    $middlewares = $match['middlewares'];
    // [AuthMiddleware::class, AdminMiddleware::class]
}
```

## Executing Middleware

### Simple Loop

```php
$match = $router->match($method, $uri);

foreach ($match['middlewares'] as $middleware) {
    $instance = new $middleware();
    $response = $instance->handle($request);
    
    if ($response !== null) {
        return $response; // Middleware returned early
    }
}

// All middleware passed, execute handler
$handler = $match['handler'];
```

### Pipeline Pattern

```php
$match = $router->match($method, $uri);

$pipeline = array_reduce(
    array_reverse($match['middlewares']),
    function ($next, $middleware) use ($container) {
        return function ($request) use ($next, $middleware, $container) {
            $instance = $container->get($middleware);
            return $instance->handle($request, $next);
        };
    },
    function ($request) use ($match, $container) {
        // Final handler
        return $this->dispatch($match['handler'], $match['params']);
    }
);

return $pipeline($request);
```

---

## Middleware Types

### Class String

```php
$router->get('/profile', [ProfileController::class, 'show'])
    ->middleware(AuthMiddleware::class);
```

### Closure

```php
$authCheck = function ($request, $next) {
    if (!$request->user()) {
        return redirect('/login');
    }
    return $next($request);
};

$router->get('/profile', [ProfileController::class, 'show'])
    ->middleware($authCheck);
```

---

## Common Middleware Examples

### Authentication

```php
class AuthMiddleware
{
    public function handle($request, $next)
    {
        if (!$request->user()) {
            return redirect('/login');
        }
        return $next($request);
    }
}
```

### Role Check

```php
class AdminMiddleware
{
    public function handle($request, $next)
    {
        if (!$request->user()?->isAdmin()) {
            abort(403);
        }
        return $next($request);
    }
}
```

### Rate Limiting

```php
class ThrottleMiddleware
{
    public function handle($request, $next)
    {
        $key = $request->ip();
        
        if ($this->rateLimiter->tooManyAttempts($key, 60)) {
            abort(429);
        }
        
        $this->rateLimiter->hit($key);
        return $next($request);
    }
}
```

### JSON Response

```php
class JsonMiddleware
{
    public function handle($request, $next)
    {
        $response = $next($request);
        
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

---

## Best Practices

::: tip Order Matters
Place authentication before authorization:

```php
->middleware(
    AuthMiddleware::class,      // Who is the user?
    AdminMiddleware::class,     // Can they access this?
    LogMiddleware::class        // Log the access
)
```
:::

::: tip Group Common Middleware
Don't repeat middleware on every route:

```php
// ❌ Bad
$router->get('/admin/users', [...])->middleware(AuthMiddleware::class, AdminMiddleware::class);
$router->get('/admin/posts', [...])->middleware(AuthMiddleware::class, AdminMiddleware::class);

// ✅ Good
$router->group('/admin', function($router) {
    $router->get('/users', [...]);
    $router->get('/posts', [...]);
}, [AuthMiddleware::class, AdminMiddleware::class]);
```
:::
