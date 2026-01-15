# Route Parameters

Capture dynamic segments from the URL using curly braces.

## Basic Parameters

```php
// Single parameter
$router->get('/users/{id}', [UserController::class, 'show']);
// Matches: /users/123, /users/abc

// Multiple parameters
$router->get('/posts/{postId}/comments/{commentId}', [CommentController::class, 'show']);
// Matches: /posts/5/comments/42
```

## Regex Patterns

Constrain parameters with regex patterns using `{name:pattern}`:

```php
// Numbers only
$router->get('/users/{id:[0-9]+}', [UserController::class, 'show']);
// Matches: /users/123
// Not: /users/abc

// Slug pattern
$router->get('/articles/{slug:[a-z0-9-]+}', [ArticleController::class, 'show']);
// Matches: /articles/hello-world
// Not: /articles/Hello_World

// Exact length
$router->get('/{lang:[a-z]{2}}/home', [HomeController::class, 'index']);
// Matches: /en/home, /ua/home
// Not: /eng/home, /e/home
```

---

## Common Patterns

### Numeric ID

```php
$router->get('/users/{id:[0-9]+}', [UserController::class, 'show']);
```

### UUID

```php
$router->get('/orders/{uuid:[a-f0-9-]{36}}', [OrderController::class, 'show']);
```

### Slug

```php
$router->get('/posts/{slug:[a-z0-9-]+}', [PostController::class, 'show']);
```

### Language Code

```php
$router->get('/{lang:[a-z]{2}}/about', [PageController::class, 'about']);
```

### Date Components

```php
$router->get('/archive/{year:[0-9]{4}}/{month:[0-9]{2}}', [ArchiveController::class, 'index']);
// Matches: /archive/2024/12
```

### Alternation

```php
$router->get('/{type:post|page|article}/{id:[0-9]+}', [ContentController::class, 'show']);
// Matches: /post/1, /page/5, /article/42
```

---

## Accessing Parameters

Parameters are returned in the `params` array:

```php
$router->get('/users/{id}/posts/{postId}', [PostController::class, 'show']);

$match = $router->match('GET', '/users/5/posts/42');

$match['params'];
// ['id' => '5', 'postId' => '42']
```

## Empty Parameters

Empty parameter values are filtered out:

```php
$router->get('/{lang:(?:[a-z]{2}\/)?}users', [UserController::class, 'index']);

$match = $router->match('GET', '/users');
$match['params']; // []

$match = $router->match('GET', '/en/users');
$match['params']; // ['lang' => 'en/']
```

---

## Complex Patterns

### Parameter with Slashes

```php
$router->get('/{path:(foo|bar)/baz}', [Controller::class, 'handle']);
// Matches: /foo/baz, /bar/baz
```

### Alternation with Length

```php
$router->get('/{code:[a-z]{2}|[0-9]{2}}/info', [InfoController::class, 'show']);
// Matches: /en/info, /12/info
// Not: /abc/info, /1/info
```

### Optional with Pattern

See [Optional Segments](/features/optional-segments) for combining parameters with optional segments.

---

## Validation

Parameters are validated during matching:

```php
$router->get('/users/{id:[0-9]+}', [UserController::class, 'show']);

$router->match('GET', '/users/123'); // Match!
$router->match('GET', '/users/abc'); // false - doesn't match pattern
```

::: tip
Define strict patterns to prevent invalid requests from reaching your controllers.
:::
