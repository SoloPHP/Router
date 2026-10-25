# Route Cache

Building a router validates every route and compiles its patterns. Without a cache, a PHP-FPM application does this on every request: about 0.1–0.3 ms for 100 routes and 1.2–2.8 ms for 1,000. `RouteCache` keeps the built router in a PHP file that OPcache holds in memory, so a request loads it in under 2 µs, without registering or building any route.

```php
use Solo\Router\RouteCache;
use Solo\Router\RouteCollector;

$cache = new RouteCache(__DIR__ . '/var/cache/routes.php');

$router = $cache->load(function (RouteCollector $routes): void {
    $routes->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
    $routes->group('/admin', function (RouteCollector $routes): void {
        $routes->get('/dashboard', [AdminController::class, 'dashboard']);
    }, [AuthMiddleware::class]);
});

$result = $router->resolve('GET', '/users/42');
```

`load()` returns the cached router. When the file is missing, it calls the callback with a new collector, builds the router, writes the file, and returns the router. The callback does not run while the cache is used, so keep all route registration inside it.

## Clearing the cache

The file is not compared with your route definitions. Delete it whenever routes change, for example as a deployment step:

```php
(new RouteCache(__DIR__ . '/var/cache/routes.php'))->clear();
```

The next request builds the router and writes the file again. Leave the cache off during development and call `build()` directly, so route changes take effect immediately.

A file written by an incompatible version of the package is ignored and replaced automatically.

## What can be cached

Handlers, middleware, and attributes must be plain PHP values: strings, integers, floats, booleans, `null`, arrays, and enums. Closures and other objects cannot be stored in a PHP file, so `load()` and `save()` throw `LogicException` naming the route instead of writing an incomplete cache:

```php
$routes->get('/users', [UserController::class, 'index']);                // Cacheable
$routes->get('/report', 'App\\Report')->middleware('auth');              // Cacheable
$routes->get('/health', fn () => 'ok');                                  // LogicException
$routes->get('/admin', 'admin')->attribute('policy', new AdminPolicy()); // LogicException
```

Reference middleware and handlers by class name or container id, and resolve them in your application.

A route that holds an enum is stored serialized and restored when a request first uses it. The rest of the file stays a constant array, which OPcache shares between requests without building it again.

## Concurrency and OPcache

The file is written to a temporary name and renamed into place, so concurrent requests read either the old file or the complete new one. On a cold cache, several requests may build the router at once; the last write wins, and all of them write the same content.

After writing or clearing the file, `RouteCache` invalidates it in OPcache. This matters in production, where `opcache.validate_timestamps` is often disabled.

OPcache does not store a file modified less than `opcache.file_update_protection` seconds ago (2 by default), in case it is still being written. For those first seconds after a write, every request compiles the file again: about 1 ms for 1,000 routes, still less than building the router. To avoid even that, write the cache during deployment, e.g. with `load()` after `clear()`, a few seconds before the application receives traffic.

## Requirements

- Enable OPcache. Without it, PHP parses the cache file on every request, which is still faster than building but much slower than loading it from OPcache.
- Store the file outside the web root, in a directory that only the application can write: `load()` executes it as PHP code.

## Storing the router elsewhere

`Router::toArray()` exports the routing tables as plain PHP values, and `Router::fromArray()` restores them. Use them to keep the router in another store, such as APCu:

```php
use Solo\Router\Router;

$data = apcu_fetch('routes');
if (is_array($data)) {
    $router = Router::fromArray($data);
} else {
    $router = $routes->build();
    apcu_store('routes', $router->toArray());
}
```

The array format is internal and versioned. `fromArray()` throws `InvalidArgumentException` for data exported by an incompatible version.

See the [RouteCache API](/api/route-cache) and [performance measurements](/guide/performance#building).
