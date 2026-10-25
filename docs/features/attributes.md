# Route Attributes

The examples configure a collector. Build a router after configuration to resolve requests. Treat the patterns below as independent examples.

```php
use Solo\Router\RouteCollector;

$routes = new RouteCollector();
```

Attributes are custom key-value data attached to a route. The router stores them and exposes them on the route returned by `resolve()`, but never interprets them: their meaning is up to your application — a required permission, a billing plan, a rate limit, a cache policy.

::: info
Route attributes are plain runtime data. They are unrelated to PHP's `#[Attribute]` syntax.
:::

## Setting Attributes

Use the fluent `attribute()` method:

```php
$routes->get('/reports', [ReportController::class, 'index'])
    ->name('reports.index')
    ->attribute('permission', 'reports.view')
    ->attribute('plan', 'pro');
```

Values can be of any type. Setting the same key again replaces its value. Arrays are PHP values; object values keep their identity and remain owned by the application. Later calls to `attribute()` change subsequent builds only.

Keys are names: an integer key or an integer string such as `'42'` throws `InvalidArgumentException`, because PHP would store it as an integer array key.

## Reading Attributes

`Matched::$route->attributes` contains the matched route's attributes, or an empty array if it has none:

```php
use Solo\Router\Results\Matched;

$router = $routes->build();
$result = $router->resolve('GET', '/reports');
if ($result instanceof Matched) {
    $result->route->attributes; // ['permission' => 'reports.view', 'plan' => 'pro']
}
```

On a `Route` object, use `getAttribute()` with an optional default, or the `attributes` property for all of them:

```php
$route = $router->getRouteByName('reports.index');

$route->getAttribute('permission');     // 'reports.view'
$route->getAttribute('rate_limit', 60); // 60 — not set, default returned
$route->attributes;                // ['permission' => 'reports.view', 'plan' => 'pro']
```

## Group Attributes

Pass attributes as the fourth parameter of `group()`, or by name. Every route in the group inherits them:

```php
$routes->group('/billing', function(RouteCollector $routes) {
    $routes->get('/invoices', [InvoiceController::class, 'index']);
    $routes->post('/invoices', [InvoiceController::class, 'store']);
}, attributes: ['permission' => 'billing.manage']);
```

Middleware and attributes are both arrays, so `group()` checks their keys and throws if they are passed in each other's place: middleware with string keys, such as `['permission' => 'billing.manage']`, or attributes with integer keys, such as `[AuthMiddleware::class]`.

## Inheritance Rules

Middleware accumulates from outer groups to the route. Attributes don't: the closest definition of a key wins — an inner group over an outer one, a route over its group. Keys that are not redefined are inherited unchanged, and values are replaced, not merged: an inner `['tags' => ['reports']]` replaces an outer `['tags' => ['admin']]`.

```php
$routes->group('/admin', function(RouteCollector $routes) {
    $routes->get('/users', [UserController::class, 'index']);
    // ['permission' => 'admin', 'plan' => 'basic']

    $routes->group('/reports', function(RouteCollector $routes) {
        $routes->get('/daily', [ReportController::class, 'daily']);
        // ['permission' => 'reports.view', 'plan' => 'basic']

        $routes->get('/export', [ReportController::class, 'export'])
            ->attribute('plan', 'pro');
        // ['permission' => 'reports.view', 'plan' => 'pro']
    }, attributes: ['permission' => 'reports.view']);
}, attributes: ['permission' => 'admin', 'plan' => 'basic']);
```

---

## Example: Permission Check

Require a permission per route, falling back to the route name when the attribute is not set. Deny by default: a route whose permission cannot be resolved is not reachable.

```php
$result = $router->resolve($method, $uri);

if ($result instanceof Matched) {
    $permission = $result->route->getAttribute('permission', $result->route->name);

    // No attribute and no name, or an explicit null: deny rather than skip the check
    if ($permission === null || !$user->can($permission)) {
        http_response_code(403);
        return;
    }
}
```

`getAttribute()` returns the default only for a missing key. A stored `null` does not fall back to the route name. Handle `NotFound` and `MethodNotAllowed` results separately before dispatching.

## Reading Attributes Outside a Request

Attributes live on routes, so tooling and tests can read them without matching a request. For example, list who may call each endpoint:

```php
foreach ($router->getRoutes() as $route) {
    $permission = $route->getAttribute('permission', $route->name);
    echo "{$route->method->value} {$route->path} → {$permission}\n";
}
```

::: tip Keep Keys in Constants
Attribute keys are plain strings, so a typo silently creates a new key. Define them once:

```php
final class RouteAttr
{
    public const PERMISSION = 'permission';
}

$routes->get('/reports', [ReportController::class, 'index'])
    ->attribute(RouteAttr::PERMISSION, 'reports.view');
```
:::
