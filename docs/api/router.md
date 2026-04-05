# Router

Base router class with optimized route matching.

```php
use Solo\Router\Router;

$router = new Router();
```

## Constructor

```php
public function __construct(array $routes = [])
```

Create a new Router instance with optional initial routes.

```php
// Empty router
$router = new Router();

// With predefined routes
$routes = [
    new Route(HttpMethod::GET, '', '/users', [UserController::class, 'index']),
];
$router = new Router($routes);
```

---

## addRoute()

```php
public function addRoute(
    string $method,
    string $path,
    mixed $handler,
    array $options = []
): Route
```

Add a new route to the router.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$method` | `string` | HTTP method (GET, HEAD, POST, PUT, PATCH, DELETE) |
| `$path` | `string` | Route path pattern |
| `$handler` | `mixed` | Route handler |
| `$options` | `array` | Optional settings |

**Options array:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `group` | `string` | `''` | URL prefix |
| `middlewares` | `array` | `[]` | Middleware array |
| `name` | `string\|null` | `null` | Route name |

**Example:**

```php
$router->addRoute('GET', '/users/{id}', [UserController::class, 'show'], [
    'group' => '/api',
    'middlewares' => [AuthMiddleware::class],
    'name' => 'users.show',
]);
```

**Throws:**

- `InvalidArgumentException` — If HTTP method is not supported

---

## match()

```php
public function match(string $method, string $uri): array|false
```

Match a request against registered routes.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$method` | `string` | HTTP method |
| `$uri` | `string` | Request URI path |

**Returns:**

On match:
```php
[
    'handler' => callable|array|string,  // Route handler
    'params' => array<string, string>,   // Captured parameters
    'middlewares' => array,              // Route middleware
    'name' => string|null,              // Route name
]
```

On no match: `false`

::: tip HEAD Fallback
Per RFC 7231, if no explicit `HEAD` route is registered, `match()` automatically falls back to the matching `GET` route. This ensures `curl -I` and monitoring tools work without registering separate HEAD routes.
:::

**Example:**

```php
$match = $router->match('GET', '/users/123');

if ($match !== false) {
    $handler = $match['handler'];     // [UserController::class, 'show']
    $params = $match['params'];       // ['id' => '123']
    $middlewares = $match['middlewares'];
    $name = $match['name'];           // 'users.show' or null
} else {
    // 404 Not Found
}
```

---

## getRoutes()

```php
public function getRoutes(): array
```

Get all registered routes.

**Returns:** `Route[]`

```php
$routes = $router->getRoutes();

foreach ($routes as $route) {
    echo $route->method->value . ' ' . $route->group . $route->path . "\n";
}
```

---

## getRouteByName()

```php
public function getRouteByName(string $name): ?Route
```

Get a route by its name.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$name` | `string` | Route name |

**Returns:** `Route|null`

**Throws:**

- `InvalidArgumentException` — If duplicate route names exist

```php
$route = $router->getRouteByName('users.show');

if ($route !== null) {
    echo $route->path;      // '/users/{id}'
    echo $route->getName(); // 'users.show'
}
```

---

## Performance Optimizations

The Router implements several optimizations:

### Static Route Cache

Routes without parameters use O(1) hash table lookup:

```php
// Static route - instant lookup
$router->addRoute('GET', '/about', [PageController::class, 'about']);
```

### Method Indexing

Routes are indexed by HTTP method to avoid checking all routes:

```php
// Only GET routes are checked for GET requests
$router->match('GET', '/users');
```

### Pattern Caching

Compiled regex patterns are cached:

```php
// Pattern compiled once, cached for subsequent matches
$router->addRoute('GET', '/users/{id}', [UserController::class, 'show']);
```

### Route Categorization

Routes are automatically categorized as static or dynamic:

```php
// Static (hash lookup)
'/about'
'/contact'

// Dynamic (regex matching)
'/users/{id}'
'/posts[/{page}]'
'/{lang:[a-z]{2}}/home'
```
