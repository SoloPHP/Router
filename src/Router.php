<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;
use Solo\Contracts\Router\RouterInterface;
use Solo\Router\Matchers\RouteMatcher;
use Solo\Router\Enums\HttpMethod;

/**
 * High-performance router with optimized route matching.
*/
class Router implements RouterInterface
{
    /** @var array<string, Route> */
    private array $staticRoutes = [];

    /** @var array<string, Route[]> */
    private array $dynamicRoutes = [];

    /** @var Route[] */
    private array $routes = [];

    /** @var array<string, int> */
    private array $routeNameIndex = [];

    /**
     * Regex matcher for dynamic and complex routes.
     */
    private readonly RouteMatcher $regexMatcher;

    /**
     * Create a new Router instance.
     *
     * @param Route[] $routes Optional initial routes
     */
    public function __construct(array $routes = [])
    {
        $this->regexMatcher = new RouteMatcher();

        foreach ($routes as $route) {
            $this->addRouteObject($route);
        }
    }

    /**
     * Add a new route to the router.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path Route path pattern
     * @param mixed $handler Route handler (callable, controller, etc.)
     * @param array{group?: string, middlewares?: array<int, callable>, name?: string|null} $options
     * @throws InvalidArgumentException If method is invalid or route name already exists
     */
    public function addRoute(
        string $method,
        string $path,
        mixed $handler,
        array $options = []
    ): void {
        $method = HttpMethod::tryFrom(strtoupper($method))
            ?? throw new InvalidArgumentException("Unsupported HTTP method: $method");

        $name = $options['name'] ?? null;
        if ($name && array_key_exists($name, $this->routeNameIndex)) {
            throw new InvalidArgumentException("Route with name '$name' already exists");
        }

        $route = new Route(
            method: $method,
            group: $options['group'] ?? '',
            path: $path,
            handler: $handler,
            middlewares: $options['middlewares'] ?? [],
            name: $name
        );

        $this->addRouteObject($route);
    }

    /**
     * Add a Route object to the router.
     *
     * @param Route $route
     */
    private function addRouteObject(Route $route): void
    {
        $this->routes[] = $route;

        if ($route->name !== null) {
            $this->routeNameIndex[$route->name] = count($this->routes) - 1;
        }

        $method = $route->method->value;
        $fullPath = $route->group . $route->path;

        // Categorize route for optimized matching
        if ($this->isStaticRoute($fullPath)) {
            // Static route - store in hash table for O(1) lookup
            $this->staticRoutes[$method . ':' . $fullPath] = $route;
        } else {
            // Dynamic or complex route - group by method
            if (!isset($this->dynamicRoutes[$method])) {
                $this->dynamicRoutes[$method] = [];
            }
            $this->dynamicRoutes[$method][] = $route;

            // Clear regex matcher cache when adding dynamic routes
            $this->regexMatcher->clearCache();
        }
    }

    /**
     * Check if route is static (no parameters or optional segments).
     *
     * @param string $path
     * @return bool
     */
    private function isStaticRoute(string $path): bool
    {
        return !str_contains($path, '{') && !str_contains($path, '[');
    }

    /**
     * Match a request against registered routes.
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return array{
     *     handler: callable|array{class-string, string}|string,
     *     params: array<string, string>,
     *     middlewares: array<int, callable>
     * }|false
     */
    public function match(string $method, string $uri): array|false
    {
        $method = strtoupper($method);

        // 1. Try static route first (fastest - O(1))
        $key = $method . ':' . $uri;
        if (isset($this->staticRoutes[$key])) {
            $route = $this->staticRoutes[$key];
            return [
                'handler' => $route->handler,
                'params' => [],
                'middlewares' => $route->middlewares,
            ];
        }

        // 2. Try dynamic routes (grouped by method)
        if (isset($this->dynamicRoutes[$method])) {
            $result = $this->regexMatcher->match($this->dynamicRoutes[$method], $method, $uri);
            if ($result !== null) {
                return $result;
            }
        }

        return false;
    }

    /**
     * Get all registered routes.
     *
     * @return Route[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get a route by its name.
     *
     * @param string $name Route name
     * @return Route|null
     */
    public function getRouteByName(string $name): ?Route
    {
        return isset($this->routeNameIndex[$name])
            ? $this->routes[$this->routeNameIndex[$name]]
            : null;
    }
}
