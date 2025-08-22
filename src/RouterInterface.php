<?php

declare(strict_types=1);

namespace Solo\Router;

/**
 * Interface for router implementations.
 */
interface RouterInterface
{
    /**
     * Adds a new route to the router.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $group Route group prefix
     * @param string $path Route path
     * @param callable|array|string $handler
     * @param array<callable> $middlewares Array of middleware functions
     * @param string|null $name Route name
     */
    public function addRoute(
        string $method,
        string $group,
        string $path,
        callable|array|string $handler,
        array $middlewares = [],
        ?string $name = null
    ): void;

    /**
     * Matches the requested method and URL against registered routes.
     *
     * @param string $requestMethod HTTP method of the request
     * @param string $url Requested URL
     * @return MatchResult|false Returns MatchResult if matched, false otherwise.
     */
    public function matchRoute(string $requestMethod, string $url): MatchResult|false;

    /**
     * Returns all registered routes.
     *
     * @return array<int, Route>
     */
    public function getRoutes(): array;

    /**
     * Returns route by name or null if not found.
     *
     * @param string $name
     * @return Route|null
     */
    public function getRouteByName(string $name): ?Route;
}
