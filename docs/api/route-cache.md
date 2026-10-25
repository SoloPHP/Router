# RouteCache

`Solo\Router\RouteCache` keeps a built [Router](/api/router) in a PHP file, so a request loads it from OPcache instead of registering and building routes. See [Route Cache](/features/caching) for usage, deployment, and limitations.

```php
use Solo\Router\RouteCache;

$cache = new RouteCache('/var/www/app/var/cache/routes.php');
```

## load()

```php
public function load(callable $define): Router
```

Returns the router stored in the file. When the file is missing, or was written by an incompatible version of the package, calls `$define` with a new [RouteCollector](/api/route-collector), builds the router, saves it, and returns it.

```php
$router = $cache->load(function (RouteCollector $routes): void {
    $routes->get('/users/{id}', [UserController::class, 'show']);
});
```

Throws:

- `InvalidArgumentException` for invalid definitions, as `build()` does;
- `LogicException` if a handler, middleware, or attribute contains a closure or another object;
- `RuntimeException` if the file cannot be written.

## save()

```php
public function save(Router $router): void
```

Writes the router to the file, replacing it atomically, and invalidates the file in OPcache. Missing directories are created. Throws `LogicException` for routes that cannot be cached and `RuntimeException` if the file cannot be written.

## clear()

```php
public function clear(): void
```

Deletes the file, if it exists, and invalidates it in OPcache, so the next `load()` builds the router again. Throws `RuntimeException` if the file cannot be deleted.
