# Optional Segments

The router supports flexible optional segment patterns using square brackets `[]`.

## Basic Optional

```php
$router->get('/posts[/{page}]', [PostController::class, 'index']);

// Matches:
// /posts        → params: []
// /posts/2      → params: ['page' => '2']
```

## Optional in the Middle

```php
$router->get('/users[/{id}]/posts', [PostController::class, 'index']);

// Matches:
// /users/posts      → params: []
// /users/123/posts  → params: ['id' => '123']
```

## Multiple Optional Segments

```php
$router->get('/api[/v{version}]/users[/{id}]', [ApiController::class, 'users']);

// Matches:
// /api/users           → params: []
// /api/v2/users        → params: ['version' => '2']
// /api/v2/users/123    → params: ['version' => '2', 'id' => '123']
// /api/users/456       → params: ['id' => '456']
```

---

## Nested Optional Segments

Optional segments can be nested:

```php
$router->get('/shop[/category/{cat}[/subcategory/{subcat}]]', [ShopController::class, 'index']);

// Matches:
// /shop                                      → params: []
// /shop/category/electronics                 → params: ['cat' => 'electronics']
// /shop/category/electronics/subcategory/phones → params: ['cat' => 'electronics', 'subcat' => 'phones']

// NOT matched (subcategory requires category):
// /shop/subcategory/phones
```

## Optional with Regex

Combine optional segments with regex patterns:

```php
$router->get('/posts[/{year:[0-9]{4}}[/{month:[0-9]{2}}[/{day:[0-9]{2}}]]]', [PostController::class, 'archive']);

// Matches:
// /posts                → params: []
// /posts/2024           → params: ['year' => '2024']
// /posts/2024/12        → params: ['year' => '2024', 'month' => '12']
// /posts/2024/12/25     → params: ['year' => '2024', 'month' => '12', 'day' => '25']

// NOT matched:
// /posts/24             → year must be 4 digits
```

---

## Optional Prefix and Suffix

```php
$router->get('[/admin]/users[.{format:json|xml}]', [UserController::class, 'index']);

// Matches:
// /users              → params: []
// /admin/users        → params: []
// /users.json         → params: ['format' => 'json']
// /admin/users.xml    → params: ['format' => 'xml']

// NOT matched:
// /users.pdf          → format must be json or xml
```

## Optional Language Prefix

```php
$router->group('[/{lang:[a-z]{2}}]', function (RouteCollector $router) {
    $router->get('[/]', [HomeController::class, 'index']);
    $router->get('/documentation', [DocsController::class, 'index']);
});

// Matches:
// /                   → params: []
// /en                 → params: ['lang' => 'en']
// /en/                → params: ['lang' => 'en']
// /documentation      → params: []
// /en/documentation   → params: ['lang' => 'en']
```

---

## Complex Example

```php
$router->get('/{lang:[a-z]{2}}[/admin]/users[/{id:[0-9]+}[/edit]]', [UserController::class, 'handle']);

// Matches:
// /en/users                  → params: ['lang' => 'en']
// /en/admin/users            → params: ['lang' => 'en']
// /en/admin/users/123        → params: ['lang' => 'en', 'id' => '123']
// /en/admin/users/123/edit   → params: ['lang' => 'en', 'id' => '123']
// /en/users/456              → params: ['lang' => 'en', 'id' => '456']

// NOT matched:
// /users                     → lang is required
```

---

## Error Handling

Invalid paths throw `InvalidArgumentException` when the route is registered:

```php
// Missing closing bracket
$router->get('/users[/{id}', fn() => '');
// InvalidArgumentException: Unmatched opening bracket(s) in: /users[/{id}

// Extra closing bracket
$router->get('/users/{id}]', fn() => '');
// InvalidArgumentException: Unmatched closing bracket at position 11 in: /users/{id}]
```

Text inside optional segments is literal, like everywhere else in the path: `/users[.{format}]` matches `/users.json`, not `/usersXjson`.

---

## Best Practices

::: tip Order Matters
Static routes (no parameters or optional segments) always win over dynamic ones, so `/users/me` works in any order next to `/users/{id}`. Dynamic routes are tried in registration order, so define narrower patterns first:

```php
// ✅ Good
$router->get('/posts/{year:[0-9]{4}}', [PostController::class, 'archive']);
$router->get('/posts/{slug}', [PostController::class, 'show']);

// ❌ Bad - /posts/2024 is matched by {slug}
$router->get('/posts/{slug}', [PostController::class, 'show']);
$router->get('/posts/{year:[0-9]{4}}', [PostController::class, 'archive']);
```
:::

::: warning Nested Requirements
Inner optional segments require outer ones:

```php
// In this route:
'/shop[/category/{cat}[/subcategory/{subcat}]]'

// subcategory is only available if category is present
```
:::
