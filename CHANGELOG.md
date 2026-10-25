# Changelog

## 4.0.0 - 2026-10-25

### Breaking changes

- Requires PHP 8.3 or later; v3 supported PHP 8.1. Projects on PHP 8.1 or 8.2 can stay on 3.5.
- `RouteCollector` is an independent builder. Call `build()` after configuring routes. It no longer accepts definitions in its constructor.
- `Router` is an immutable runtime with `resolve()`, returning `Matched`, `NotFound`, or `MethodNotAllowed`.
- Registration returns mutable `RouteDefinition` objects. Runtime `Route` objects expose readonly metadata and a full `path`.
- Duplicate paths/names and invalid patterns fail during `build()`.
- The core no longer depends on `solophp/contracts` or implements its v3 interfaces. Integrations must use the new API.
- All registration methods accept opaque `mixed` handlers; dispatch remains an application concern.

### Matching and performance

- Static paths use direct lookup. The dynamic routes of each method compile into one regex shaped as an order-preserving prefix tree, so a lookup is a single `preg_match()` per method. Patterns too large for PCRE are split in order.
- Lookup time no longer grows with the number of routes, including routes that start with a parameter or a regex constraint, such as `/{lang:[a-z]{2}}/…`: about 0.4–0.5 µs instead of 44–167 µs with 1,000 routes. Routes that start with an optional segment are still tried one by one, in 3–7 µs instead of 49–191 µs.
- Misses (404) and 405 checks run one pattern per method instead of scanning candidates.
- `RouteCache` keeps a built router in a PHP file; with OPcache, a request loads it in under 2 µs instead of registering and building the routes. `Router::toArray()` and `Router::fromArray()` export and restore routers for other stores. Runtime `Route` objects are created on first use.
- Static priority, dynamic registration order, explicit HEAD priority, advanced patterns, and raw parameter bytes remain supported.
- PCRE execution failures throw `RuntimeException` rather than appearing as routing misses.

### Validation and fixes

- A route that can never match, because an earlier route with the same method has the same pattern (e.g. `/users/{uid}` after `/users/{id}`), fails during `build()`.
- Regexes in parameters and raw groups are read as PCRE reads them, including `\Q…\E`, character classes, and POSIX classes. Syntax that could affect other routes in the combined pattern is rejected: groups that do not close inside a parameter, backtracking verbs, recursion, subroutine calls, named groups, absolute backreferences (use `\g{-1}`), conditional groups, comments, callouts, `\c`, the `x` option, and raw groups that only set inline options, such as `(?i)` (scope them as `(?i:...)`).
- Whitespace around a parameter name and pattern is ignored, so `{id: \d+}` no longer creates a route that never matches. Empty patterns such as `{id:}` are rejected.
- `#` in a parameter regex no longer breaks the pattern delimiter.
- A string key in the `middlewares` option of `addRoute()` throws, as it already did for `group()`; runtime middleware is always a list.
- Route paths containing NUL bytes are rejected, static paths included.
- Errors in a route path name the route the same way, e.g. `Route 'GET /a{2}' has an invalid parameter name '2'`.

See the [migration guide](https://solophp.github.io/Router/guide/migration-v4) and [performance measurements](https://solophp.github.io/Router/guide/performance). Earlier releases are described in [GitHub Releases](https://github.com/SoloPHP/Router/releases).
