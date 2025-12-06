<?php

declare(strict_types=1);

namespace Solo\Router;

/**
 * Route collector with fluent API for route configuration.
 */
final class RouteCollector extends Router
{
    private string $group = '';

    /** @var array<int, callable|string> */
    private array $groupMiddleware = [];

    /**
     * Adds a GET route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function get(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute('GET', $path, $handler);
    }

    /**
     * Adds a POST route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function post(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute('POST', $path, $handler);
    }

    /**
     * Adds a PUT route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function put(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute('PUT', $path, $handler);
    }

    /**
     * Adds a PATCH route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function patch(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute('PATCH', $path, $handler);
    }

    /**
     * Adds a DELETE route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function delete(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute('DELETE', $path, $handler);
    }

    /**
     * Adds an HTTP route with the specified method.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    private function addHttpRoute(
        string $method,
        string $path,
        callable|array|string $handler
    ): Route {
        return $this->addRoute(
            method: $method,
            path: $path,
            handler: $handler,
            options: [
                'group' => $this->group,
                'middlewares' => $this->groupMiddleware,
            ]
        );
    }

    /**
     * Creates a route group with prefix and middleware.
     *
     * @param array<int, callable|string> $middlewares
     */
    public function group(
        string $prefix,
        callable $callback,
        array $middlewares = []
    ): void {
        $previousGroup = $this->group;
        $previousMiddleware = $this->groupMiddleware;

        $this->group = $previousGroup . $prefix;
        $this->groupMiddleware = [...$middlewares, ...$previousMiddleware];

        $callback($this);

        $this->group = $previousGroup;
        $this->groupMiddleware = $previousMiddleware;
    }
}
