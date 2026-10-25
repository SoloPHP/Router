# Router

The runtime router is a snapshot of definitions collected by [RouteCollector](/api/route-collector). Build it after registering routes:

```php
use Solo\Router\RouteCollector;

$routes = new RouteCollector();
$routes->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
$router = $routes->build();
```

`Router` has no registration methods or `match()` method. Configure the collector, then resolve requests on the built router. Later edits to the collector do not change an existing router.

## resolve()

```php
public function resolve(
    string $method,
    string $uri
): Matched|NotFound|MethodNotAllowed
```

The three result classes are in `Solo\Router\Results`:

| Result | Readonly properties | Meaning |
|--------|---------------------|---------|
| `Matched` | `Route $route`, `array $params` | A route accepts this method and path |
| `NotFound` | None | No route accepts this path |
| `MethodNotAllowed` | `array $allowedMethods` | The path exists for other methods |

```php
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;

$result = $router->resolve($method, $uri);

if ($result instanceof Matched) {
    $handler = $result->route->handler;
    $params = $result->params;
    $middlewares = $result->route->middlewares;
    $permission = $result->route->getAttribute('permission');
} elseif ($result instanceof MethodNotAllowed) {
    http_response_code(405);
    header('Allow: ' . implode(', ', $result->allowedMethods));
} else {
    http_response_code(404);
}
```

Pass a URI **path**, without its query string. Methods are matched case-sensitively: `get` does not match a `GET` route. Registration through `RouteCollector::addRoute()` accepts lowercase method names and normalizes them.

### Matching order

For a given method, static routes take precedence over dynamic routes. Dynamic routes are tried in registration order, including when their prefixes differ. A HEAD request first tries explicit HEAD routes, then falls back to GET if no HEAD route matches the path. An explicit dynamic HEAD route therefore takes precedence over a static GET fallback.

Parameters retain the bytes supplied in `$uri`. For `/files/{name}`, `/files/a%2Fb` produces `['name' => 'a%2Fb']`; decoding is the application's responsibility. Empty parameter captures are omitted.

Route syntax and regex validity are checked during `build()`; invalid definitions throw `InvalidArgumentException`. A PCRE execution failure during `resolve()` or `getAllowedMethods()` (for example, exceeding the backtracking limit for a URI) throws `RuntimeException` rather than returning a 404/405 result. Its message names the HTTP method, such as `Cannot match GET routes: Backtrack limit exhausted`, because the routes of a method are matched together.

## getAllowedMethods()

```php
public function getAllowedMethods(string $uri): array
```

Returns the methods accepting the path as `list<string>`, ordered `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`. HEAD is included whenever GET matches. A missing path returns `[]`.

```php
$router->getAllowedMethods('/users/123'); // ['GET', 'HEAD']
```

`resolve()` already returns this information on `MethodNotAllowed`; there is no need to call `getAllowedMethods()` again to produce a 405 response.

The core router has no external package dependencies. Application integrations can depend on `Solo\Router\Router`; separate contracts can describe this API without preserving v3 registration or result types. See [migration](/guide/migration-v4#contracts-and-integrations).

## getRoutes()

```php
public function getRoutes(): array
```

Returns `list<Route>` in registration order. Each [Route](/api/route) is a runtime snapshot; its `path` already includes group prefixes.

```php
foreach ($router->getRoutes() as $route) {
    echo $route->method->value . ' ' . $route->path . "\n";
}
```

## getRouteByName()

```php
public function getRouteByName(string $name): ?Route
```

Returns a runtime route or `null`:

```php
$route = $router->getRouteByName('users.show');
if ($route !== null) {
    echo $route->path; // '/users/{id}'
    echo $route->name; // 'users.show'
}
```

## Compiled patterns

Static routes use a hash table by path and method. The dynamic routes of each method are compiled into one regular expression, so a lookup runs a single `preg_match()` per method. Very large tables are split into several patterns only when PCRE rejects one as too large; they are tried in order.

Inside a pattern, routes form a prefix tree in registration order: routes with a common start share it, and PCRE skips a whole subtree as soon as its prefix fails. Lookup time therefore depends little on the number of routes or on their position, including routes that start with a parameter or a regex constraint.

Sharing never changes which route matches first:

- A shared prefix contains only literal text and parameters that consume exactly one segment: `{name}`, or a regex that cannot match `/` (such as `\d+`, `[a-z]{2}` or `en|ru`), followed by `/` or the end of the path. Optional segments, raw groups with quantifiers, and parameters that can span segments are never shared, because backtracking into them could reach a later route first. Routes that start with one are tried one by one, so their lookup time grows with their number: see [optional segments](/guide/performance#optional-segments).
- A route moves ahead of an earlier route only when their literal text differs at the same position, so no URI can match both.

The matched route is identified by a PCRE mark, and parameters are read by group number. A 405 check tries the patterns of the other methods.

Building costs far more than a lookup. [RouteCache](/features/caching) stores built routers in PHP files that OPcache keeps in memory. See [performance measurements](/guide/performance) for lookup and construction costs.

## toArray()

```php
public function toArray(): array
```

Exports the routing tables as plain PHP values, e.g. for `var_export()` in a cache file. Throws `LogicException` if a handler, middleware, or attribute contains a closure or another object: only scalars, `null`, arrays, and enums can be exported. The format is internal; pass it only to `fromArray()` of the same package version.

## fromArray()

```php
public static function fromArray(array $data): Router
```

Restores a router from `toArray()` data without registering or building routes. Throws `InvalidArgumentException` for data exported by an incompatible version. Runtime `Route` objects are created when first used and then shared, as in a built router.
