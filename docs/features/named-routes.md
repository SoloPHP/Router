# Named Routes

Assign a name while configuring a route, then retrieve the final route from the built router.

```php
use Solo\Router\RouteCollector;

$routes = new RouteCollector();
$routes->get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show');
$routes->post('/posts', [PostController::class, 'store'])
    ->name('posts.store');

$router = $routes->build();
$route = $router->getRouteByName('users.show');
if ($route !== null) {
    echo $route->path; // '/users/{id}'
    echo $route->name; // 'users.show'
}
```

Names are unique across all methods and groups. The collector has no name lookup: names become indexed when the router is built.

## Names in groups

```php
$routes->group('/admin', function (RouteCollector $routes) {
    $routes->get('/dashboard', [AdminController::class, 'dashboard'])
        ->name('admin.dashboard');
}, [AuthMiddleware::class]);

$router = $routes->build();
$dashboard = $router->getRouteByName('admin.dashboard');
echo $dashboard->path; // '/admin/dashboard'
```

Group prefixes affect paths, not names. A consistent `resource.action` or `area.resource.action` naming scheme keeps names easy to identify.

## Duplicate name protection

Duplicate names are rejected by `build()`:

```php
$routes = new RouteCollector();
$routes->get('/users', [UserController::class, 'index'])->name('users');
$routes->get('/members', [MemberController::class, 'index'])->name('users');
$router = $routes->build(); // Throws InvalidArgumentException
```

Renaming a definition changes subsequent builds only. Existing routers keep their original name indexes.

## Current route name

Read the name from a successful result for navigation or logging:

```php
use Solo\Router\Results\Matched;

$result = $router->resolve($method, $uri);
$currentRouteName = $result instanceof Matched ? $result->route->name : null;
$isDashboard = $currentRouteName === 'admin.dashboard';
```

## URL generation

A name lookup returns a route pattern. To build a path from it, use [UrlGenerator](/features/url-generation):

```php
use Solo\Router\UrlGenerator;

$urls = new UrlGenerator($router);
echo $urls->generate('users.show', ['id' => 42]); // '/users/42'
```
