<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;

/**
 * Route collector with support for grouping and naming routes.
 */
class RouteCollector extends Router
{
    private string $group = '';
    private array $groupMiddleware = [];

    /**
     * Adds a GET route.
     */
    public function get(
        string $path,
        callable|array|string $handler,
        array $middlewares = [],
        ?string $name = null
    ): self {
        return $this->addHttpRoute('GET', $path, $handler, $middlewares, $name);
    }

    /**
     * Adds a POST route.
     */
    public function post(
        string $path,
        callable|array|string $handler,
        array $middlewares = [],
        ?string $name = null
    ): self {
        return $this->addHttpRoute('POST', $path, $handler, $middlewares, $name);
    }

    /**
     * Adds a PUT route.
     */
    public function put(
        string $path,
        callable|array|string $handler,
        array $middlewares = [],
        ?string $name = null
    ): self {
        return $this->addHttpRoute('PUT', $path, $handler, $middlewares, $name);
    }

    /**
     * Adds a PATCH route.
     */
    public function patch(
        string $path,
        callable|array|string $handler,
        array $middlewares = [],
        ?string $name = null
    ): self {
        return $this->addHttpRoute('PATCH', $path, $handler, $middlewares, $name);
    }

    /**
     * Adds a DELETE route.
     */
    public function delete(
        string $path,
        callable|array|string $handler,
        array $middlewares = [],
        ?string $name = null
    ): self {
        return $this->addHttpRoute('DELETE', $path, $handler, $middlewares, $name);
    }

    /**
     * Adds an HTTP route with the specified method.
     *
     * @param array<callable> $middlewares Array of middleware functions
     */
    private function addHttpRoute(
        string $method,
        string $path,
        callable|array|string $handler,
        array $middlewares,
        ?string $name = null
    ): self {
        $this->addRoute(
            method: $method,
            group: $this->group,
            path: $path,
            handler: $handler,
            middlewares: array_merge($middlewares, $this->groupMiddleware),
            name: $name
        );

        return $this;
    }

    /**
     * Creates a route group with prefix and middleware.
     *
     * @param array<callable> $middlewares Array of middleware functions
     */
    public function group(
        string $prefix,
        callable $callback,
        array $middlewares = []
    ): void {
        $previousGroup = $this->group;
        $previousMiddleware = $this->groupMiddleware;

        $this->group = $previousGroup . $prefix;
        $this->groupMiddleware = array_merge($middlewares, $previousMiddleware);

        $callback($this);

        $this->group = $previousGroup;
        $this->groupMiddleware = $previousMiddleware;
    }
}
