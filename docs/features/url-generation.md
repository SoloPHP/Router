# URL Generation

`UrlGenerator` builds the path of a [named route](/features/named-routes) from parameter values: the reverse of `resolve()`.

```php
use Solo\Router\RouteCollector;
use Solo\Router\UrlGenerator;

$routes = new RouteCollector();
$routes->get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
$routes->get('/tasks/{id:\d+}', [TaskController::class, 'show'])->name('tasks.show');

$router = $routes->build();
$urls = new UrlGenerator($router);

$urls->generate('tasks.show', ['id' => 42]);       // '/tasks/42'
$urls->generate('tasks.index', [], ['page' => 2]); // '/tasks?page=2'
```

Values are strings or integers. The third argument is encoded by `http_build_query()` as RFC 3986 requires, so a space becomes `%20`. The result is a path: prepend a base path or a host in your application if it needs one.

## Encoding and patterns

Values are encoded with `rawurlencode()`, and each encoded value must match the pattern of its parameter:

```php
$routes->get('/users/{name}', [UserController::class, 'show'])->name('users.show');
$routes->get('/files/{path:.+}', [FileController::class, 'show'])->name('files.show');

$urls->generate('users.show', ['name' => 'Jane Doe']);  // '/users/Jane%20Doe'
$urls->generate('users.show', ['name' => 'a/b']);       // '/users/a%2Fb'
$urls->generate('files.show', ['path' => 'docs/a.md']); // '/files/docs/a.md'
$urls->generate('tasks.show', ['id' => 'abc']);         // Throws: must match '\d+'
```

A `/` stays as it is only where the pattern accepts it, as in `{path:.+}`; elsewhere it is encoded as `%2F`. `resolve()` returns parameters as they appear in the path, so decode them with `rawurldecode()` to get the values back.

## Optional segments

An optional segment is generated only if a parameter in it has a value; its other parameters are then required. `null` counts as no value.

```php
$routes->get('/archive[/{year:\d{4}}[/{month:\d{2}}]]', [ArchiveController::class, 'index'])
    ->name('archive');

$urls->generate('archive');                                    // '/archive'
$urls->generate('archive', ['year' => 2026]);                  // '/archive/2026'
$urls->generate('archive', ['year' => 2026, 'month' => '10']); // '/archive/2026/10'
$urls->generate('archive', ['month' => '10']);                 // Throws: 'year' is missing
```

Optional segments without parameters, such as `[/]` or `[/admin]`, are left out. A path left empty becomes `/`, so a home route under an optional language prefix works without a language:

```php
$routes->group('[/{lang:[a-z]{2}}]', function (RouteCollector $routes) {
    $routes->get('[/]', [HomeController::class, 'index'])->name('home');
});

$urls->generate('home');                   // '/'
$urls->generate('home', ['lang' => 'en']); // '/en'
```

## Errors

`generate()` throws `InvalidArgumentException` when:

- no route has the name;
- a parameter is missing, or its value does not match its pattern;
- the route has no parameter with a given name: pass query values in the third argument;
- the path contains a raw regex group such as `(admin|user)`, which no value fills: use a parameter such as `{area:admin|user}` instead.

The message names the route and the parameter:

```text
Cannot generate a URL for route 'tasks.show': parameter 'id' must match '\d+', got 'abc'
```

The generator does not check which route a path reaches. If a static route or an earlier route of the same method also matches the path, `resolve()` returns that route, as for any request.

## Cached routers

The generator reads route paths, which [RouteCache](/features/caching) and `Router::toArray()` keep, so it works the same with a router loaded from a cache:

```php
use Solo\Router\RouteCache;

$router = (new RouteCache(__DIR__ . '/var/cache/routes.php'))->load($defineRoutes);
$urls = new UrlGenerator($router);
```

See the [UrlGenerator API](/api/url-generator).
