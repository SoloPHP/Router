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
| `$method` | `string` | HTTP method (GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS) |
| `$path` | `string` | Route path pattern |
| `$handler` | `mixed` | Route handler |
| `$options` | `array` | Optional settings |

**Options array:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `group` | `string` | `''` | URL prefix |
| `middlewares` | `array` | `[]` | Middleware array |
| `name` | `string\|null` | `null` | Route name |
| `attributes` | `array` | `[]` | Custom [attributes](/features/attributes) |

**Example:**

```php
$router->addRoute('GET', '/users/{id}', [UserController::class, 'show'], [
    'group' => '/api',
    'middlewares' => [AuthMiddleware::class],
    'name' => 'users.show',
    'attributes' => ['permission' => 'users.view'],
]);
```

**Throws:**

- `InvalidArgumentException` — If HTTP method is not supported
- `InvalidArgumentException` — If the path is invalid (unmatched brackets, invalid or duplicate parameter names)
- `InvalidArgumentException` — If a route with the same method and full path, or with the same name, is already registered
- `InvalidArgumentException` — If an attribute key is an integer or an integer string such as `'42'`

---

## match()

```php
public function match(string $method, string $uri): array|false
```

Match a request against registered routes.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$method` | `string` | HTTP method, as sent by the client |
| `$uri` | `string` | Request URI path |

The method is matched case-sensitively, as [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110#section-9.1) defines it: `get` does not match a `GET` route. Only registration is case-insensitive, so `addRoute('get', ...)` registers a `GET` route.

**Returns:**

On match:
```php
[
    'handler' => callable|array|string,  // Route handler
    'params' => array<string, string>,   // Captured parameters
    'middlewares' => array,              // Route middleware
    'name' => string|null,               // Route name
    'attributes' => array<string, mixed>, // Custom attributes, [] if none
]
```

On no match: `false`

::: tip HEAD Fallback
Per RFC 7231, if no explicit `HEAD` route is registered, `match()` automatically falls back to the matching `GET` route. This ensures `curl -I` and monitoring tools work without registering separate HEAD routes.
:::

Static routes take precedence over dynamic ones; dynamic routes are tried in registration order.

Parameters are returned as they appear in `$uri`. If you pass the raw request path, they are still percent-encoded: `/files/a%2Fb` gives `['name' => 'a%2Fb']` for `/files/{name}`. Decode them with `rawurldecode()` after matching.

**Throws:**

- `InvalidArgumentException` — If a custom parameter regex (e.g. `{id:a{2,1}}`) is not a valid regular expression. Custom regexes are compiled on first use, so this surfaces on the first request that reaches the route.

**Example:**

```php
$match = $router->match('GET', '/users/123');

if ($match !== false) {
    $handler = $match['handler'];     // [UserController::class, 'show']
    $params = $match['params'];       // ['id' => '123']
    $middlewares = $match['middlewares'];
    $name = $match['name'];           // 'users.show' or null
    $attributes = $match['attributes']; // ['permission' => 'users.view'] or []
} else {
    // 404 Not Found
}
```

---

## getAllowedMethods()

```php
public function getAllowedMethods(string $uri): array
```

Get the HTTP methods that have a route matching the URI. Use it to tell `405 Method Not Allowed` from `404 Not Found` and to fill the `Allow` header. `HEAD` is included whenever `GET` matches.

`Router` implements `Solo\Contracts\Router\AllowedMethodsInterface` with this method, so code that depends only on the contracts can check for it with `instanceof`.

**Returns:** `list<string>`, in the order `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`

```php
$match = $router->match($method, $uri);

if ($match === false) {
    $allowed = $router->getAllowedMethods($uri);
    if ($allowed !== []) {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowed));
    } else {
        http_response_code(404);
    }
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

### Prefix Filtering

Each dynamic route remembers the literal text before its first parameter, optional segment or regex group. Routes whose prefix does not match the URI are skipped without running a regex:

```php
// Only routes starting with '/users/' run their regex for /users/123
$router->addRoute('GET', '/users/{id}', [UserController::class, 'show']);
$router->addRoute('GET', '/posts/{id}', [PostController::class, 'show']); // skipped
```

### Allowed Methods in One Pass

`getAllowedMethods()` checks each dynamic path once, whatever number of methods share it: `/users/{id}` registered for `GET`, `PUT` and `DELETE` runs one regex, not three.

### One-Pass Compilation

Paths are compiled to regular expressions in a single pass when the route is registered, so syntax errors fail fast; a path shared by several methods is compiled once. PCRE compiles the resulting regex lazily on first use.

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
