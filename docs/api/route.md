# Route and RouteDefinition

v4 separates the mutable configuration object from the runtime route.

| Type | Created by | Purpose |
|------|------------|---------|
| `RouteDefinition` | `RouteCollector::get()`, `post()`, `addRoute()`, etc. | Configure a route before a build |
| `Route` | Building a router | Read the route selected by `resolve()` or `getRouteByName()` |

## Route

`Solo\Router\Route` exposes readonly properties:

| Property | Type | Meaning |
|----------|------|---------|
| `method` | `HttpMethod` | HTTP method enum |
| `path` | `string` | Complete path pattern, including group prefixes |
| `handler` | `mixed` | Application handler |
| `middlewares` | `list<callable\|string>` | Middleware in outer-to-inner order |
| `name` | `string\|null` | Final route name |
| `attributes` | `array<string, mixed>` | Final attributes after group inheritance |

```php
$router = $routes->build();
$route = $router->getRouteByName('users.show');

if ($route !== null) {
    echo $route->method->value; // 'GET'
    echo $route->path;          // '/api/users/{id}'
    echo $route->name;          // 'users.show'
    $middlewares = $route->middlewares;
    $attributes = $route->attributes;
}
```

There is no separate runtime `group` or `fullPath` property. `path` is the full pattern. Runtime routes have no fluent setters, `getName()`, `getMiddlewares()`, or `getAttributes()`; read the corresponding property.

### getAttribute()

```php
public function getAttribute(string $key, mixed $default = null): mixed
```

Returns the default only when the key is missing. A stored `null` remains `null`.

```php
$route->getAttribute('permission');     // 'users.view', if configured
$route->getAttribute('rate_limit', 60); // 60, if missing
```

Handler, middleware, and attribute arrays are stored as PHP values, so later changes to a definition do not reach a built route. Values stored through PHP references inside those arrays stay shared.

Readonly route metadata does not make application-owned objects immutable. Handler objects, closures, and object-valued attributes retain their identity across builds.

## RouteDefinition

`Solo\Router\RouteDefinition` retains the fluent configuration API:

```php
$definition = $routes->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show')
    ->middleware(AuthMiddleware::class, LogMiddleware::class)
    ->attribute('permission', 'users.view');
```

### name()

```php
public function name(string $name): self
```

Sets or replaces the name. Uniqueness is checked by `build()`, so definitions can be renamed while configuring routes.

### middleware()

```php
public function middleware(callable|string ...$middlewares): self
```

Appends middleware after any inherited group middleware. Repeated calls preserve their order.

### attribute()

```php
public function attribute(string $key, mixed $value): self
```

Sets or replaces one custom value, including a value inherited from a group. Integer strings such as `'42'` throw `InvalidArgumentException` because PHP stores them as integer array keys.

### Reading a definition

The builder object retains its getters:

```php
$definition->getName();
$definition->getMiddlewares();
$definition->getAttribute('permission', 'public');
$definition->getAttributes();
```

Definitions also expose readonly `method`, `group`, `path`, `fullPath`, and `handler` properties. Here `path` is local to the group and `fullPath` includes the group prefix. On a built `Route`, `path` alone contains the full path.

Changing a definition after a build changes only subsequent builds. Read final runtime metadata from the built router.

## HttpMethod

`Solo\Router\Enums\HttpMethod` is a string-backed enum with cases `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, and `OPTIONS`.

```php
use Solo\Router\Enums\HttpMethod;

if ($route->method === HttpMethod::GET) {
    echo $route->method->value; // 'GET'
}
```
