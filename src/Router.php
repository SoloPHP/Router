<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;

/**
 * Base router class for handling HTTP routes.
 */
class Router implements RouterInterface
{
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @var array<string,int> Map of route name to index in $routes
     */
    protected array $routeNameIndex = [];

    public function __construct(
        protected array $routes = []
    ) {
    }

    /**
     * Adds a new route to the router.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $group Route group prefix
     * @param string $path Route path
     * @param callable|array|string $handler
     * @param array<callable> $middlewares Array of middleware functions
     * @param string|null $name
     */
    public function addRoute(
        string $method,
        string $group,
        string $path,
        callable|array|string $handler,
        array $middlewares = [],
        ?string $name = null
    ): void {
        $method = strtoupper($method);

        if (!in_array($method, self::HTTP_METHODS, true)) {
            throw new InvalidArgumentException("Unsupported HTTP method: {$method}");
        }

        if ($name !== null) {
            if (isset($this->routeNameIndex[$name])) {
                throw new InvalidArgumentException("Route with name '{$name}' already exists");
            }
        }

        $this->routes[] = new Route(
            method: $method,
            group: $group,
            path: $path,
            handler: $handler,
            middlewares: $middlewares,
            name: $name
        );

        if ($name !== null) {
            $this->routeNameIndex[$name] = array_key_last($this->routes);
        }
    }

    /**
     * Matches the requested method and URL against registered routes.
     *
     * @param string $requestMethod HTTP method of the request
     * @param string $url Requested URL
     * @return MatchResult|false Returns MatchResult if matched, false otherwise.
     */
    public function matchRoute(string $requestMethod, string $url): MatchResult|false
    {
        $requestMethod = strtoupper($requestMethod);

        foreach ($this->routes as $route) {
            if ($requestMethod !== $route->method) {
                continue;
            }

            $pattern = $this->buildPattern($route->group . $route->path);

            if (preg_match($pattern, $url, $matches)) {
                $args = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return new MatchResult($route, $args);
            }
        }

        return false;
    }

    /**
     * Returns all registered routes.
     *
     * @return array<string|int, array{
     *     method: string,
     *     group: string,
     *     path: string,
     *     handler: callable|array|string,
     *     middleware: array<callable>,
     *     name: string|null
     * }>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Returns a route by its name or null if not found.
     *
     * @return array{
     *     method: string,
     *     group: string,
     *     path: string,
     *     handler: callable|array|string,
     *     middleware: array<callable>,
     *     name: string|null
     * }|null
     */
    public function getRouteByName(string $name): ?Route
    {
        if (!isset($this->routeNameIndex[$name])) {
            return null;
        }

        $index = $this->routeNameIndex[$name];
        return $this->routes[$index] ?? null;
    }

    /**
     * Builds a regular expression pattern for route matching.
     */
    private function buildPattern(string $path): string
    {
        $pattern = str_replace('/', '\/', $path);
        $pattern = preg_replace('/\[(?![^{]*})/', '(?:', $pattern);
        $pattern = preg_replace('/](?![^{]*})/', ')?', $pattern);
        $pattern = preg_replace('/{(\w+):([^}]+)}/', '(?<$1>$2)', $pattern);
        $pattern = preg_replace('/{(\w+)}/', '(?<$1>[^\/]+)', $pattern);

        return '/^' . $pattern . '$/';
    }
}
