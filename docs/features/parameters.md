# Route Parameters

The examples configure a collector. Build a router after configuration to resolve requests. Treat the patterns below as independent examples.

```php
use Solo\Router\RouteCollector;

$routes = new RouteCollector();
```

Capture dynamic segments from the URL using curly braces.

## Basic Parameters

```php
// Single parameter
$routes->get('/users/{id}', [UserController::class, 'show']);
// Matches: /users/123, /users/abc

// Multiple parameters
$routes->get('/posts/{postId}/comments/{commentId}', [CommentController::class, 'show']);
// Matches: /posts/5/comments/42
```

## Regex Patterns

Constrain parameters with regex patterns using `{name:pattern}`:

```php
// Numbers only
$routes->get('/users/{id:[0-9]+}', [UserController::class, 'show']);
// Matches: /users/123
// Not: /users/abc

// Slug pattern
$routes->get('/articles/{slug:[a-z0-9-]+}', [ArticleController::class, 'show']);
// Matches: /articles/hello-world
// Not: /articles/Hello_World

// Exact length
$routes->get('/{lang:[a-z]{2}}/home', [HomeController::class, 'index']);
// Matches: /en/home, /ua/home
// Not: /eng/home, /e/home
```

---

## Common Patterns

### Numeric ID

```php
$routes->get('/users/{id:[0-9]+}', [UserController::class, 'show']);
```

### UUID

```php
$routes->get('/orders/{uuid:[a-f0-9-]{36}}', [OrderController::class, 'show']);
```

### Slug

```php
$routes->get('/posts/{slug:[a-z0-9-]+}', [PostController::class, 'show']);
```

### Language Code

```php
$routes->get('/{lang:[a-z]{2}}/about', [PageController::class, 'about']);
```

### Date Components

```php
$routes->get('/archive/{year:[0-9]{4}}/{month:[0-9]{2}}', [ArchiveController::class, 'index']);
// Matches: /archive/2024/12
```

### Alternation

```php
$routes->get('/{type:post|page|article}/{id:[0-9]+}', [ContentController::class, 'show']);
// Matches: /post/1, /page/5, /article/42
```

---

## Accessing Parameters

Parameters are returned in the `params` array:

```php
$routes->get('/users/{id}/posts/{postId}', [PostController::class, 'show']);

use Solo\Router\Results\Matched;

$router = $routes->build();
$result = $router->resolve('GET', '/users/5/posts/42');
if ($result instanceof Matched) {
    $result->params; // ['id' => '5', 'postId' => '42']
}
```

Parameters retain the bytes passed to `resolve()`. For `/files/{name}`, `/files/a%2Fb` produces `a%2Fb`; apply `rawurldecode()` in your application if decoding is required.

## Empty Parameters

Empty parameter values are filtered out:

```php
$routes->get('/{lang:(?:[a-z]{2}\/)?}users', [UserController::class, 'index']);

$router = $routes->build();
$result = $router->resolve('GET', '/users');
if ($result instanceof Matched) {
    $result->params; // []
}

$result = $router->resolve('GET', '/en/users');
if ($result instanceof Matched) {
    $result->params; // ['lang' => 'en/']
}
```

---

## Complex Patterns

### Parameter with Slashes

```php
$routes->get('/{path:(foo|bar)/baz}', [Controller::class, 'handle']);
// Matches: /foo/baz, /bar/baz
```

### Alternation with Length

```php
$routes->get('/{code:[a-z]{2}|[0-9]{2}}/info', [InfoController::class, 'show']);
// Matches: /en/info, /12/info
// Not: /abc/info, /1/info
```

### Optional with Pattern

See [Optional Segments](/features/optional-segments) for combining parameters with optional segments.

---

## Parameter Names

A name starts with a letter or underscore and contains only letters, digits and underscores. Each name can appear once per route (group prefix included). Invalid definitions throw `InvalidArgumentException` at `build()`:

```php
$routes->get('/users/{id}/{id}', fn () => '');
$router = $routes->build(); // Throws: duplicate parameter 'id'
```

```php
$routes = new Solo\Router\RouteCollector();
$routes->get('/page{2}', fn () => '');
$router = $routes->build(); // Throws: invalid parameter name '2'
```

Whitespace around the name and the pattern is ignored, because a raw space never appears in a request path: `{ id : \d+ }` is the same as `{id:\d+}`. An empty pattern such as `{id:}` throws.

Text outside parameters and `(...)` groups is literal: `/v1.0/{id}` matches `/v1.0/5`, not `/v1x0/5`.

---

## Validation

Valid regex constraints filter requests during matching. Invalid regex syntax is rejected when building:

```php
$routes->get('/users/{id:[0-9]+}', [UserController::class, 'show']);

$router = $routes->build();
$router->resolve('GET', '/users/123'); // Matched
$router->resolve('GET', '/users/abc'); // NotFound, if no other route accepts this path
```

The routes of a method are matched by one combined pattern, so the regex of a parameter or a raw `(...)` group must not change how other routes match. The router reads it as PCRE does, and these are rejected at `build()`:

- groups that do not close inside the parameter, such as `{x:a)|(b}`;
- backtracking control verbs and other `(*…)` syntax (`(*COMMIT)`, `(*SKIP)`, `(*MARK)`, …);
- recursion and subroutine calls (`(?R)`, `(?1)`, `(?&name)`, `\g<1>`);
- named groups and named references (`(?P<name>…)`, `(?<name>…)`, `\k<name>`): use a separate parameter instead;
- absolute backreferences such as `\1` or `\g{1}`: groups are numbered across the whole route, so use a relative reference such as `\g{-1}`;
- conditional groups `(?(…)…)`, comments `(?#…)`, callouts `(?C…)`, `\c` escapes, and `\N{…}`;
- inline options other than `i`, `m`, `n`, `s`, and `U`, such as `x`;
- raw groups that only set inline options, such as `/(?i)about`, because the options would also apply to later routes: scope them to a group, as in `/(?i:about)`.

Lookarounds, atomic and branch reset groups, character classes (POSIX classes such as `[[:alpha:]]` included), `\Q…\E`, alternation, plain capturing groups, `\x{…}`, and `\p{…}` are supported. `#` needs no escaping.

A route with the same method and the same compiled pattern as an earlier route could never match, so `build()` rejects it as well:

```php
$routes->get('/users/{id}', [UserController::class, 'show']);
$routes->get('/users/{uid}', [MemberController::class, 'show']);
$router = $routes->build(); // Throws: 'GET /users/{uid}' can never match
```

::: tip
Define strict patterns to prevent invalid requests from reaching your controllers. Constraints do not slow down lookup: see [compiled patterns](/api/router#compiled-patterns). Optional segments at the start of a path do, though far less than in v3: see [optional segments](/guide/performance#optional-segments).
:::
