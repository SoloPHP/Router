# Routing benchmarks

Two microbenchmarks with their own isolated dependencies. They are excluded from the Composer package.

- `lookup.php` — lookup speed in a prebuilt route table, in microseconds per lookup.
- `build.php` — cost of preparing a router on every request: registering the routes, building the table and one lookup, in milliseconds. The `solo-cached` column loads a warm `RouteCache` file instead; it needs OPcache.

Neither measures full HTTP requests, dispatch, middleware or I/O.

## Libraries

| Library | Version | Measured API |
|---|---|---|
| Solo Router | `../src` (or `SOLO_SRC`) | `RouteCollector::build()`, `Router::resolve()` |
| Leaf | 5.0.1 | `Leaf\Router::findRoute(null, $uri)` |
| Symfony Routing | 7.4.21 | `CompiledUrlMatcher::match()` |
| Slim | 4.15.3 (FastRoute 1.3.1) | `Slim\Routing\Dispatcher::dispatch()` |

Laravel's route cache uses Symfony's compiled matcher. Laravel's uncompiled `RouteCollection` and Symfony's `UrlMatcher` match linearly and were about two orders of magnitude slower in earlier runs, so they are not included.

Leaf reads the request method from `$_SERVER` and returns positional parameters, so it is measured only for GET requests in the first two layouts. Symfony and FastRoute do not support optional segments before other segments, so they skip the `optional-locale` layout.

## Workload

Each table contains exactly 100 or 1,000 GET routes: a resource endpoint and the same endpoint with an ID, interleaved in registration order.

| Layout | Resource endpoint | With ID |
|---|---|---|
| `separate` | `/resourceN` | `/resourceN/{id}` |
| `shared` | `/api/resourceN` | `/api/resourceN/{id}` |
| `tenant` | `/api/{tenant}/resourceN` | `/api/{tenant}/resourceN/{id}` |
| `locale` | `/{lang:[a-z]{2}}/resourceN` | `/{lang:[a-z]{2}}/resourceN/{id}` |
| `optional-locale` | `[/{lang:[a-z]{2}}]/resourceN` | `[/{lang:[a-z]{2}}]/resourceN/{id}` |
| `numeric` | `/resourceN` | `/resourceN/{id:\d+}` |

Lookup profiles cycle through all routes with varying IDs: `static` and `dynamic` hit only resource or ID endpoints, `mixed` alternates them, `last` always requests the last ID endpoint, `miss` appends `/missing` (404), `405` sends POST, and `head` exercises the GET fallback.

All requests are prepared before timing. Every result, including the route name and parameters, is checked for every library before the timed runs, which also warms routes and PCRE caches. Each cell is the median of 7 runs of 10,000 lookups; the run order of the libraries rotates.

## Running

```bash
composer install --working-dir=benchmarks --no-plugins --no-scripts
php -d opcache.enable_cli=1 -d opcache.jit=disable benchmarks/lookup.php
php -d opcache.enable_cli=1 -d opcache.jit=disable benchmarks/build.php
```

Options: `--libraries=solo,leaf,symfony,slim`, `--layouts=...`, `--counts=100,1000`, `--profiles=...` (lookup only), `--runs=7`, `--lookups=10000` and `--json=file` for a machine-readable report.

Environment variables:

- `SOLO_SRC` measures other Solo sources, e.g. a checkout of a previous version, with the same workload.
- `BENCHMARK_VENDOR` points to an existing vendor directory with these libraries instead of `benchmarks/vendor`.

Without a local PHP, run the same commands in the official image, e.g. `docker run --rm -v "$PWD:/app" -w /app php:8.4-cli php ...`.

## Results

[`results/`](results) contains the tables and JSON reports from 25 October 2026: PHP 8.4.21 CLI in an ARM64 Linux container on Apple Silicon, OPcache enabled, JIT disabled, PCRE 10.44 with JIT, no Xdebug. With the same workload, `*-v3.*` files measure Solo Router v3.5.0. See the [performance guide](../docs/guide/performance.md) for a summary.

Solo Router 3.x needs `solophp/contracts`; the benchmark detects its API (`Router::match()` followed by `getAllowedMethods()` on a miss) when `SOLO_SRC` points to v3 sources.

These are synthetic workloads on one machine without confidence intervals; treat small differences as noise.
