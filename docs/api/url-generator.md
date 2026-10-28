# UrlGenerator

`Solo\Router\UrlGenerator` generates the paths of the named routes of a [Router](/api/router). See [URL Generation](/features/url-generation) for examples.

```php
use Solo\Router\UrlGenerator;

$urls = new UrlGenerator($router);
```

It works with any router, including one restored by [RouteCache](/api/route-cache) or `Router::fromArray()`.

## generate()

```php
public function generate(
    string $name,
    array $params = [],
    array $query = []
): string
```

| Argument | Type | Meaning |
|----------|------|---------|
| `$name` | `string` | Route name |
| `$params` | `array<string, string\|int\|null>` | Values by parameter name; `null` counts as no value |
| `$query` | `array` | Query values, encoded by `http_build_query()` with `PHP_QUERY_RFC3986` |

Returns the path of the route with its parameters filled in, followed by `?` and the query string when `$query` produces one:

- literal text of the path stays as it is;
- `{name}` and `{name:regex}` take the value encoded with `rawurlencode()`, keeping `/` where the pattern accepts it; the encoded value must match the pattern;
- an optional segment `[...]` is generated only if a parameter in it has a value, and its other parameters are then required;
- a path left empty, e.g. by `[/]` without a value for an optional prefix, becomes `/`.

Throws `InvalidArgumentException` if no route has the name, if a parameter is missing, unknown, or does not match its pattern, or if the path contains a raw regex group `(...)`.
