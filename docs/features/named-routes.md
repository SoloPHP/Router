# Named Routes

Assign names to routes for easy referencing.

## Naming Routes

Use the fluent `name()` method:

```php
$router->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show');

$router->post('/posts', [PostController::class, 'store'])
    ->name('posts.store');
```

## Retrieving Routes by Name

```php
$route = $router->getRouteByName('users.show');

if ($route) {
    echo $route->path;      // '/users/{id}'
    echo $route->getName(); // 'users.show'
}
```

## Naming Conventions

Common patterns for naming routes:

```php
// Resource-style naming
$router->get('/users', [UserController::class, 'index'])->name('users.index');
$router->get('/users/create', [UserController::class, 'create'])->name('users.create');
$router->post('/users', [UserController::class, 'store'])->name('users.store');
$router->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
$router->get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
$router->put('/users/{id}', [UserController::class, 'update'])->name('users.update');
$router->delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');

// Nested resources
$router->get('/posts/{postId}/comments', [CommentController::class, 'index'])
    ->name('posts.comments.index');

// API versioning
$router->get('/api/v1/users', [ApiV1UserController::class, 'index'])
    ->name('api.v1.users.index');
```

---

## Named Routes in Groups

Routes in groups can still have names:

```php
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard'])
        ->name('admin.dashboard');
    
    $router->get('/users', [AdminController::class, 'users'])
        ->name('admin.users');
}, [AuthMiddleware::class]);

// Retrieve
$dashboard = $router->getRouteByName('admin.dashboard');
```

---

## Duplicate Name Protection

The router throws an exception for duplicate names:

```php
$router->get('/users', [UserController::class, 'index'])->name('users');
$router->get('/members', [MemberController::class, 'index'])->name('users');

// Later when accessing:
$router->getRouteByName('users');
// throws InvalidArgumentException: Route with name 'users' already exists
```

::: warning
The exception is thrown lazily when `getRouteByName()` is called, not when defining routes.
:::

---

## Use Cases

### URL Generation

```php
class UrlGenerator
{
    public function __construct(private Router $router) {}
    
    public function route(string $name, array $params = []): string
    {
        $route = $this->router->getRouteByName($name);
        
        if (!$route) {
            throw new RuntimeException("Route '$name' not found");
        }
        
        $path = $route->group . $route->path;
        
        foreach ($params as $key => $value) {
            $path = preg_replace('/\{' . $key . '(?::[^}]+)?\}/', $value, $path);
        }
        
        // Remove optional segments without values
        $path = preg_replace('/\[[^\]]*\{[^}]+\}[^\]]*\]/', '', $path);
        $path = str_replace(['[', ']'], '', $path);
        
        return $path;
    }
}

// Usage
$url = $urlGenerator->route('users.show', ['id' => 123]);
// Returns: /users/123
```

### Redirects

```php
class RedirectResponse
{
    public static function toRoute(string $name, array $params = []): self
    {
        global $router;
        $route = $router->getRouteByName($name);
        $url = self::buildUrl($route, $params);
        
        return new self($url, 302);
    }
}

// Usage
return RedirectResponse::toRoute('users.show', ['id' => $user->id]);
```

### Active Link Detection

```php
function isActiveRoute(string $name): bool
{
    global $router, $currentRouteName;
    return $currentRouteName === $name;
}

// In template
<a href="/dashboard" class="<?= isActiveRoute('admin.dashboard') ? 'active' : '' ?>">
    Dashboard
</a>
```

---

## Best Practices

::: tip Use Descriptive Names
Choose names that clearly identify the route's purpose:

```php
// ✅ Good
->name('users.profile.update')
->name('api.v2.orders.cancel')

// ❌ Bad
->name('route1')
->name('update')
```
:::

::: tip Consistent Naming Pattern
Follow a consistent pattern across your application:

```php
// Pattern: resource.action or area.resource.action
'users.index'
'users.show'
'admin.users.index'
'api.v1.users.index'
```
:::
