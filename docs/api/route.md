# Route

Route definition with fluent configuration API.

```php
use Solo\Router\Route;
use Solo\Router\Enums\HttpMethod;
```

## Constructor

```php
public function __construct(
    HttpMethod $method,
    string $group,
    string $path,
    mixed $handler,
    array $middlewares = [],
    ?string $name = null
)
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$method` | `HttpMethod` | HTTP method enum |
| `$group` | `string` | URL prefix from group |
| `$path` | `string` | Route path pattern |
| `$handler` | `mixed` | Route handler |
| `$middlewares` | `array` | Initial middleware |
| `$name` | `string\|null` | Route name |

**Example:**

```php
$route = new Route(
    method: HttpMethod::GET,
    group: '/api',
    path: '/users/{id}',
    handler: [UserController::class, 'show'],
    middlewares: [AuthMiddleware::class],
    name: 'users.show'
);
```

---

## Public Properties

All properties are `readonly`:

| Property | Type | Description |
|----------|------|-------------|
| `$method` | `HttpMethod` | HTTP method enum |
| `$group` | `string` | URL prefix |
| `$path` | `string` | Route path pattern |
| `$handler` | `mixed` | Route handler |

```php
echo $route->method->value;  // 'GET'
echo $route->group;          // '/api'
echo $route->path;           // '/users/{id}'
```

---

## name()

```php
public function name(string $name): self
```

Set the route name. Returns `$this` for chaining.

```php
$router->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show');
```

---

## getName()

```php
public function getName(): ?string
```

Get the route name.

```php
$name = $route->getName(); // 'users.show' or null
```

---

## middleware()

```php
public function middleware(callable|string ...$middlewares): self
```

Add middleware to the route. Returns `$this` for chaining.

**Single middleware:**

```php
$router->get('/profile', [ProfileController::class, 'show'])
    ->middleware(AuthMiddleware::class);
```

**Multiple middleware:**

```php
$router->get('/admin', [AdminController::class, 'index'])
    ->middleware(AuthMiddleware::class, AdminMiddleware::class, LogMiddleware::class);
```

**Chained calls:**

```php
$router->get('/admin/users', [AdminUserController::class, 'index'])
    ->middleware(AuthMiddleware::class)
    ->middleware(AdminMiddleware::class)
    ->name('admin.users');
```

---

## getMiddlewares()

```php
public function getMiddlewares(): array
```

Get all middleware assigned to the route.

```php
$middlewares = $route->getMiddlewares();
// [AuthMiddleware::class, AdminMiddleware::class]
```

---

## HttpMethod Enum

```php
namespace Solo\Router\Enums;

enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
}
```

**Usage:**

```php
use Solo\Router\Enums\HttpMethod;

$route = new Route(
    method: HttpMethod::POST,
    // ...
);

// Access value
echo $route->method->value; // 'POST'

// Compare
if ($route->method === HttpMethod::GET) {
    // ...
}
```

---

## Fluent API Example

```php
$router->get('/admin/users/{id}/edit', [AdminUserController::class, 'edit'])
    ->name('admin.users.edit')
    ->middleware(AuthMiddleware::class, AdminMiddleware::class);

// Later
$route = $router->getRouteByName('admin.users.edit');

echo $route->getName();            // 'admin.users.edit'
echo $route->path;                 // '/admin/users/{id}/edit'
print_r($route->getMiddlewares()); // [AuthMiddleware::class, AdminMiddleware::class]
```

---

## Full Path

To get the complete route path including the group prefix:

```php
$fullPath = $route->group . $route->path;
// '/api/users/{id}'
```
