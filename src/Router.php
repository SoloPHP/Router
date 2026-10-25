<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Solo\Router\Enums\HttpMethod;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;
use Solo\Router\Results\NotFound;
use UnitEnum;

/**
 * Immutable runtime built by RouteCollector::build() or restored by fromArray().
 *
 * @phpstan-type RouteData array{string, string, mixed, list<callable|string>, string|null, array<string, mixed>}
 *               Method, full path, handler, middlewares, name and attributes
 */
final class Router
{
    /** Version of the toArray() format; changes whenever the compiled data does. */
    private const int FORMAT = 2;

    /** @var array<int, Route> Runtime routes, created on first use */
    private array $instances = [];

    /**
     * @internal Use RouteCollector::build() or Router::fromArray() to create a router.
     * @param list<RouteData|string> $routes A string is a route that toArray() serialized
     * @param array<string, int> $names Route ID by name
     * @param array<string, array<string, int>> $static Route ID by path and method
     * @param array<string, list<string>> $dynamic Combined patterns by method, tried in order
     * @param array<int, array<int, string>> $params Parameter name by group number, by dynamic route ID
     */
    public function __construct(
        private readonly array $routes,
        private readonly array $names,
        private readonly array $static,
        private readonly array $dynamic,
        private readonly array $params
    ) {
    }

    /**
     * Restores a router exported by toArray(), e.g. from RouteCache. Pass only data from toArray():
     * routes that hold enums are stored serialized and unserialized when first used.
     *
     * @param array<mixed> $data
     * @throws InvalidArgumentException If the data is not an export of this router version
     */
    public static function fromArray(array $data): self
    {
        $valid = ($data['format'] ?? null) === self::FORMAT;
        foreach (['routes', 'names', 'static', 'dynamic', 'params'] as $key) {
            $valid = $valid && is_array($data[$key] ?? null);
        }
        if (!$valid) {
            throw new InvalidArgumentException('Unsupported router data: build the router again');
        }

        /**
         * @var array{
         *     routes: list<RouteData|string>,
         *     names: array<string, int>,
         *     static: array<string, array<string, int>>,
         *     dynamic: array<string, list<string>>,
         *     params: array<int, array<int, string>>
         * } $data
         */
        return new self($data['routes'], $data['names'], $data['static'], $data['dynamic'], $data['params']);
    }

    /**
     * Static routes precede dynamic routes for each method; dynamic routes keep
     * registration order. Explicit HEAD routes precede the GET fallback.
     * Methods are case-sensitive; parameters retain their original URI bytes.
     *
     * @throws RuntimeException If PCRE fails while matching, e.g. on the backtracking limit
     */
    public function resolve(string $method, string $uri): Matched|NotFound|MethodNotAllowed
    {
        $static = $this->static[$uri] ?? [];
        if (isset($static[$method])) {
            return new Matched($this->route($static[$method]), []);
        }
        if (isset($this->dynamic[$method]) && ($matched = $this->matchDynamic($method, $uri)) !== null) {
            return $matched;
        }
        if ($method === 'HEAD') {
            if (isset($static['GET'])) {
                return new Matched($this->route($static['GET']), []);
            }
            if (isset($this->dynamic['GET']) && ($matched = $this->matchDynamic('GET', $uri)) !== null) {
                return $matched;
            }
        }

        $allowed = $this->allowedMethods($uri, $static, $method);

        return $allowed === [] ? new NotFound() : new MethodNotAllowed($allowed);
    }

    /**
     * @return list<string> Methods in HttpMethod order, including HEAD for GET.
     * @throws RuntimeException If PCRE fails while matching, e.g. on the backtracking limit
     */
    public function getAllowedMethods(string $uri): array
    {
        return $this->allowedMethods($uri, $this->static[$uri] ?? [], '');
    }

    /** @return list<Route> */
    public function getRoutes(): array
    {
        return array_map($this->route(...), array_keys($this->routes));
    }

    public function getRouteByName(string $name): ?Route
    {
        $routeId = $this->names[$name] ?? null;

        return $routeId === null ? null : $this->route($routeId);
    }

    /**
     * Exports the routing tables as plain PHP values, e.g. for var_export() in a cache file.
     * The format is internal: only fromArray() of the same router version reads it.
     *
     * A route that holds an enum is exported serialized. var_export() writes an enum as a class
     * constant, which PHP fetches on every include, so OPcache could not keep the arrays around it
     * as constants: every load would build the whole route table again.
     *
     * @return array<string, mixed>
     * @throws LogicException If a handler, middleware or attribute holds a closure or another object;
     *                        only scalars, null, arrays and enums can be exported
     */
    public function toArray(): array
    {
        $routes = $this->routes;
        foreach ($routes as $routeId => $route) {
            if (is_string($route)) {
                continue; // Serialized by an earlier export
            }
            [$method, $path, $handler, $middlewares, , $attributes] = $route;
            $enum = false;
            $parts = ['handler' => $handler, 'middleware' => $middlewares, 'attributes' => $attributes];
            foreach ($parts as $part => $value) {
                $type = self::unexportableType($value, $enum);
                if ($type !== null) {
                    throw new LogicException(
                        "Route '$method $path' cannot be exported: its $part contains $type; "
                        . 'only scalars, null, arrays and enums can be exported'
                    );
                }
            }
            if ($enum) {
                $routes[$routeId] = serialize($route);
            }
        }

        return [
            'format' => self::FORMAT,
            'routes' => $routes,
            'names' => $this->names,
            'static' => $this->static,
            'dynamic' => $this->dynamic,
            'params' => $this->params,
        ];
    }

    private function route(int $routeId): Route
    {
        if (!isset($this->instances[$routeId])) {
            $route = $this->routes[$routeId];
            if (is_string($route)) {
                /** @var RouteData $route A route with an enum, serialized by toArray() */
                $route = unserialize($route);
            }
            [$method, $path, $handler, $middlewares, $name, $attributes] = $route;
            $this->instances[$routeId] = new Route(
                HttpMethod::from($method),
                $path,
                $handler,
                $middlewares,
                $name,
                $attributes
            );
        }

        return $this->instances[$routeId];
    }

    private function matchDynamic(string $method, string $uri): ?Matched
    {
        foreach ($this->dynamic[$method] as $pattern) {
            $result = preg_match($pattern, $uri, $matches);
            if ($result === 1) {
                $routeId = (int) $matches['MARK'];
                $params = [];
                foreach ($this->params[$routeId] as $group => $name) {
                    if (isset($matches[$group]) && $matches[$group] !== '') {
                        $params[$name] = $matches[$group];
                    }
                }

                return new Matched($this->route($routeId), $params);
            }
            if ($result === false) {
                throw $this->matchingFailed($method);
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $static Static route IDs for the URI by method
     * @param string $tried Method that resolve() already tried for this URI
     * @return list<string>
     */
    private function allowedMethods(string $uri, array $static, string $tried): array
    {
        $methods = $static; // Only the keys matter
        foreach ($this->dynamic as $method => $patterns) {
            if (isset($methods[$method]) || $method === $tried || ($tried === 'HEAD' && $method === 'GET')) {
                continue;
            }
            foreach ($patterns as $pattern) {
                $result = preg_match($pattern, $uri);
                if ($result === 1) {
                    $methods[$method] = 0;
                    break;
                }
                if ($result === false) {
                    throw $this->matchingFailed($method);
                }
            }
        }
        if ($methods === []) {
            return [];
        }
        if (isset($methods['GET'])) {
            $methods['HEAD'] = 0;
        }

        $allowed = [];
        foreach (HttpMethod::cases() as $case) {
            if (isset($methods[$case->value])) {
                $allowed[] = $case->value;
            }
        }

        return $allowed;
    }

    private function matchingFailed(string $method): RuntimeException
    {
        return new RuntimeException("Cannot match $method routes: " . preg_last_error_msg());
    }

    /**
     * Returns the type of the first value var_export() cannot restore, or null. Sets $enum if the
     * value holds an enum.
     */
    private static function unexportableType(mixed $value, bool &$enum, int $depth = 0): ?string
    {
        if ($value instanceof UnitEnum) {
            $enum = true;
            return null;
        }
        if ($value === null || is_scalar($value)) {
            return null;
        }
        if (!is_array($value)) {
            return get_debug_type($value);
        }
        if ($depth >= 64) {
            return 'an array nested too deeply';
        }
        foreach ($value as $item) {
            $type = self::unexportableType($item, $enum, $depth + 1);
            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }
}
