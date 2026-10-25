# Performance

Version 4 compiles the dynamic routes of each method into one regular expression shaped as an order-preserving prefix tree. With 1,000 routes, lookups took **0.1–0.6 µs** in every measured layout except optional prefixes (3–7 µs), compared with **4.6–191 µs** in v3.5. Building a router costs 1.7–2 times as much as in v3.5, but with `RouteCache` a request loads a prebuilt router in **under 2 µs**, independent of the number of routes.

These are routing microbenchmarks, **not full-application requests per second**. They include neither HTTP serving nor controller, middleware, database, or network work.

## Lookup with 1,000 routes

Microseconds per lookup, median of seven runs of 10,000 lookups. Each table has 1,000 GET routes: a resource endpoint and the same endpoint with an ID, interleaved. Requests cycle through all routes.

| Layout | Request | v3.5 | v4 | Leaf 5 | Symfony compiled | Slim 4 |
|--------|---------|-----:|---:|-------:|-----------------:|-------:|
| `/resourceN`, `/resourceN/{id}` | static | 0.19 | 0.13 | 0.17 | 0.27 | 0.24 |
| | dynamic | 4.70 | 0.43 | 0.54 | 0.57 | 2.07 |
| | last route | 8.63 | 0.42 | 0.47 | 0.54 | 4.30 |
| | 404 | 16.82 | 0.31 | 0.33 | 0.67 | 7.53 |
| | 405 | 8.68 | 0.44 | — | 4.39 | 2.28 |
| `/api/resourceN/{id}` | dynamic | 4.60 | 0.45 | 17.26 | 0.59 | 2.30 |
| | 404 | 16.94 | 0.33 | 39.17 | 0.67 | 9.01 |
| `/api/{tenant}/resourceN/{id}` | dynamic | 48.42 | 0.54 | — | 0.73 | 6.79 |
| | 404 | 183.77 | 0.40 | — | 0.77 | 29.08 |
| `/{lang:[a-z]{2}}/resourceN/{id}` | dynamic | 43.70 | 0.53 | — | 0.73 | 7.61 |
| | last route | 87.01 | 0.51 | — | 0.65 | 16.94 |
| | 404 | 167.15 | 0.42 | — | 0.83 | 33.61 |
| | 405 | 80.28 | 0.54 | — | 7.58 | 8.39 |
| `[/{lang:[a-z]{2}}]/resourceN/{id}` | dynamic | 49.12 | 3.20 | — | — | — |
| | last route | 95.16 | 6.78 | — | — | — |
| | 404 | 190.81 | 6.10 | — | — | — |
| `/resourceN/{id:\d+}` | dynamic | 4.65 | 0.44 | — | 0.59 | 2.08 |

“Static” requests the resource endpoints; in the tenant and locale layouts they are dynamic too. “404” appends `/missing`; “405” sends POST to GET routes. HEAD requests falling back to GET cost about the same as dynamic hits. A dash means the library does not support that layout or request with the measured API.

## Lookup with 100 routes

| Layout | Request | v3.5 | v4 | Symfony compiled | Slim 4 |
|--------|---------|-----:|---:|-----------------:|-------:|
| `/resourceN/{id}` | dynamic | 0.76 | 0.31 | 0.45 | 0.56 |
| | 404 | 1.87 | 0.21 | 0.52 | 1.10 |
| `/{lang:[a-z]{2}}/resourceN/{id}` | dynamic | 3.42 | 0.34 | 0.49 | 0.77 |
| | 404 | 11.78 | 0.21 | 0.55 | 1.78 |
| `[/{lang:[a-z]{2}}]/resourceN/{id}` | dynamic | 3.78 | 0.53 | — | — |

## Optional segments

Routes share prefixes only where sharing cannot change which route matches first. An optional segment can be present or absent, so backtracking into a shared one could reach a later route before an earlier one. Routes that start with an optional segment are therefore listed one by one inside the pattern. PCRE still rejects them quickly (3–7 µs with 1,000 such routes, against 49–191 µs in v3.5), but lookup time grows with their number. The same applies to parameters whose regex can match `/`, such as `{path:.*}`.

## Building

Milliseconds to register the routes, build the router and resolve one request, median in a warm process. Without a route cache, a PHP-FPM application pays this on every request. “v4 cached” loads a warm [RouteCache](/features/caching) file instead and resolves one request.

| Layout | Routes | v3.5 | v4 | v4 cached | Leaf 5 | Symfony compiled | Slim 4 |
|--------|-------:|-----:|---:|----------:|-------:|-----------------:|-------:|
| `/resourceN/{id}` | 100 | 0.07 | 0.11 | 0.0014 | 0.12 | 0.59 | 0.17 |
| | 1,000 | 0.63 | 1.18 | 0.0016 | 1.13 | 9.56 | 10.18 |
| `/api/{tenant}/resourceN/{id}` | 100 | 0.11 | 0.20 | 0.0015 | — | 0.99 | 0.14 |
| | 1,000 | 1.16 | 2.29 | 0.0018 | — | 11.34 | 1.46 |
| `/{lang:[a-z]{2}}/resourceN/{id}` | 100 | 0.15 | 0.26 | 0.0015 | — | 0.99 | 0.14 |
| | 1,000 | 1.49 | 2.84 | 0.0018 | — | 11.34 | 1.51 |

Building validates every pattern and builds the prefix tree; PHP caches the compiled regex itself across requests in the same worker. A cached router skips both: OPcache serves the tables from shared memory, and `Route` objects are created only for the routes a request uses. Symfony and Laravel applications normally cache their compiled matchers too; their uncached numbers above are not what they pay in production. Clear the cache whenever routes change.

## Measurement method and limits

Measurements were recorded on **25 October 2026** with PHP 8.4.21 CLI in an ARM64 Linux container on Apple Silicon. OPcache was enabled and PHP JIT disabled; PCRE 10.44 ran with its JIT; Xdebug was absent.

- Libraries: Solo Router v3.5.0 and this version, Leaf 5.0.1, Symfony Routing 7.4.21 (`CompiledUrlMatcher`), and Slim 4.15.3 with FastRoute 1.3.1. v3.5 resolves with `match()` followed by `getAllowedMethods()` on a miss, the work needed to tell 404 from 405.
- All requests were prepared before timing. Every result, including route names and parameters, was checked for every library before the timed runs, which also warmed routes and PCRE caches. The run order of the libraries rotated.
- Leaf was measured only for GET requests in layouts with a literal first segment; Symfony and FastRoute do not support optional segments before other segments.
- This is one machine and a synthetic workload, without confidence intervals or randomized request distributions. Minor differences should not be treated as stable advantages.

The scripts, the full tables for every layout and request type, and the JSON reports are in [`benchmarks/`](https://github.com/SoloPHP/Router/tree/main/benchmarks) in the repository.
