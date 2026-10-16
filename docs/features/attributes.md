# Route Attributes

Attributes are custom key-value data attached to a route. The router stores them and returns them from `match()`, but never interprets them: their meaning is up to your application — a required permission, a billing plan, a rate limit, a cache policy.

::: info
Route attributes are plain runtime data. They are unrelated to PHP's `#[Attribute]` syntax.
:::

## Setting Attributes

Use the fluent `attribute()` method:

```php
$router->get('/reports', [ReportController::class, 'index'])
    ->name('reports.index')
    ->attribute('permission', 'reports.view')
    ->attribute('plan', 'pro');
```

Values can be of any type. Setting the same key again replaces its value.

Keys are names: an integer key or an integer string such as `'42'` throws `InvalidArgumentException`, because PHP would store it as an integer array key.

## Reading Attributes

`match()` returns the matched route's attributes, or an empty array if it has none:

```php
$match = $router->match('GET', '/reports');

$match['attributes']; // ['permission' => 'reports.view', 'plan' => 'pro']
```

On a `Route` object, use `getAttribute()` with an optional default, or `getAttributes()` for all of them:

```php
$route = $router->getRouteByName('reports.index');

$route->getAttribute('permission');     // 'reports.view'
$route->getAttribute('rate_limit', 60); // 60 — not set, default returned
$route->getAttributes();                // ['permission' => 'reports.view', 'plan' => 'pro']
```

## Group Attributes

Pass attributes as the fourth parameter of `group()`, or by name. Every route in the group inherits them:

```php
$router->group('/billing', function(RouteCollector $router) {
    $router->get('/invoices', [InvoiceController::class, 'index']);
    $router->post('/invoices', [InvoiceController::class, 'store']);
}, attributes: ['permission' => 'billing.manage']);
```

Middleware and attributes are both arrays, so `group()` checks their keys and throws if they are passed in each other's place: middleware with string keys, such as `['permission' => 'billing.manage']`, or attributes with integer keys, such as `[AuthMiddleware::class]`.

## Inheritance Rules

Middleware accumulates from outer groups to the route. Attributes don't: the closest definition of a key wins — an inner group over an outer one, a route over its group. Keys that are not redefined are inherited unchanged, and values are replaced, not merged: an inner `['tags' => ['reports']]` replaces an outer `['tags' => ['admin']]`.

```php
$router->group('/admin', function(RouteCollector $router) {
    $router->get('/users', [UserController::class, 'index']);
    // ['permission' => 'admin', 'plan' => 'basic']

    $router->group('/reports', function(RouteCollector $router) {
        $router->get('/daily', [ReportController::class, 'daily']);
        // ['permission' => 'reports.view', 'plan' => 'basic']

        $router->get('/export', [ReportController::class, 'export'])
            ->attribute('plan', 'pro');
        // ['permission' => 'reports.view', 'plan' => 'pro']
    }, attributes: ['permission' => 'reports.view']);
}, attributes: ['permission' => 'admin', 'plan' => 'basic']);
```

---

## Example: Permission Check

Require a permission per route, falling back to the route name when the attribute is not set. Deny by default: a route whose permission cannot be resolved is not reachable.

```php
$match = $router->match($method, $uri);

if ($match !== false) {
    $attributes = $match['attributes'];
    $permission = array_key_exists('permission', $attributes) ? $attributes['permission'] : $match['name'];

    // No attribute and no name, or an explicit null: deny rather than skip the check
    if ($permission === null || !$user->can($permission)) {
        http_response_code(403);
        return;
    }
}
```

`array_key_exists()` keeps the same rule as `getAttribute()`: a stored `null` is a value, not a missing key, so it does not fall back to the route name.

## Reading Attributes Outside a Request

Attributes live on routes, so tooling and tests can read them without matching a request. For example, list who may call each endpoint:

```php
foreach ($router->getRoutes() as $route) {
    $permission = $route->getAttribute('permission', $route->getName());
    echo "{$route->method->value} {$route->fullPath} → {$permission}\n";
}
```

::: tip Keep Keys in Constants
Attribute keys are plain strings, so a typo silently creates a new key. Define them once:

```php
final class RouteAttr
{
    public const PERMISSION = 'permission';
}

$router->get('/reports', [ReportController::class, 'index'])
    ->attribute(RouteAttr::PERMISSION, 'reports.view');
```
:::
