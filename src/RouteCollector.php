<?php

declare(strict_types=1);

namespace Solo\Router;

use Solo\Router\Enums\HttpMethod;

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
        return $this->addHttpRoute(HttpMethod::GET, $path, $handler);
    }

    /**
     * Adds a HEAD route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function head(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute(HttpMethod::HEAD, $path, $handler);
    }

    /**
     * Adds a POST route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function post(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute(HttpMethod::POST, $path, $handler);
    }

    /**
     * Adds a PUT route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function put(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute(HttpMethod::PUT, $path, $handler);
    }

    /**
     * Adds a PATCH route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function patch(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute(HttpMethod::PATCH, $path, $handler);
    }

    /**
     * Adds a DELETE route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function delete(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute(HttpMethod::DELETE, $path, $handler);
    }

    /**
     * Adds an OPTIONS route.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function options(string $path, callable|array|string $handler): Route
    {
        return $this->addHttpRoute(HttpMethod::OPTIONS, $path, $handler);
    }

    /**
     * Adds an HTTP route with the specified method.
     *
     * @param callable|array{class-string, string}|class-string $handler
     */
    private function addHttpRoute(
        HttpMethod $method,
        string $path,
        callable|array|string $handler
    ): Route {
        return $this->addRouteObject(new Route(
            method: $method,
            group: $this->group,
            path: $path,
            handler: $handler,
            middlewares: $this->groupMiddleware
        ));
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
        $this->groupMiddleware = [...$previousMiddleware, ...$middlewares];

        try {
            $callback($this);
        } finally {
            $this->group = $previousGroup;
            $this->groupMiddleware = $previousMiddleware;
        }
    }
}
