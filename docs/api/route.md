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
    ?string $name = null,
    array $attributes = []
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
| `$attributes` | `array` | Initial [attributes](/features/attributes) |

**Example:**

```php
$route = new Route(
    method: HttpMethod::GET,
    group: '/api',
    path: '/users/{id}',
    handler: [UserController::class, 'show'],
    middlewares: [AuthMiddleware::class],
    name: 'users.show',
    attributes: ['permission' => 'users.view']
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

Throws `InvalidArgumentException` if the router already has another route with this name.

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

## attribute()

```php
public function attribute(string $key, mixed $value): self
```

Set a custom attribute. The router stores it and returns it from `match()` without interpreting it. Replaces an earlier value for the same key, including one inherited from a group. Returns `$this` for chaining.

Throws `InvalidArgumentException` if the key is an integer string such as `'42'`, which PHP would store as an integer key. The constructor's `$attributes` and the `attributes` option of `addRoute()` follow the same rule.

```php
$router->get('/reports', [ReportController::class, 'index'])
    ->attribute('permission', 'reports.view')
    ->attribute('plan', 'pro');
```

See [Route Attributes](/features/attributes).

---

## getAttribute()

```php
public function getAttribute(string $key, mixed $default = null): mixed
```

Get one attribute. Returns `$default` only when the key is not set; a stored `null` is returned as `null`.

```php
$route->getAttribute('permission');     // 'reports.view'
$route->getAttribute('rate_limit', 60); // 60
```

---

## getAttributes()

```php
public function getAttributes(): array
```

Get all attributes, including those inherited from groups.

```php
$attributes = $route->getAttributes();
// ['permission' => 'reports.view', 'plan' => 'pro']
```

---

## HttpMethod Enum

```php
namespace Solo\Router\Enums;

enum HttpMethod: string
{
    case GET = 'GET';
    case HEAD = 'HEAD';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';
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
    ->middleware(AuthMiddleware::class, AdminMiddleware::class)
    ->attribute('permission', 'users.edit');

// Later
$route = $router->getRouteByName('admin.users.edit');

echo $route->getName();                   // 'admin.users.edit'
echo $route->path;                        // '/admin/users/{id}/edit'
print_r($route->getMiddlewares());        // [AuthMiddleware::class, AdminMiddleware::class]
echo $route->getAttribute('permission');  // 'users.edit'
```

---

## Full Path

The complete route path including the group prefix:

```php
$route->fullPath;
// '/api/users/{id}'
```
