<?php declare(strict_types=1);

namespace Solo\Router;

use Solo\Router;
use InvalidArgumentException;

/**
 * Route collector with support for grouping and naming routes.
 */
class RouteCollector extends Router
{
    private string $group = '';
    private array $groupMiddleware = [];
    private ?int $lastIndex = null;

    /**
     * Adds a GET route.
     */
    public function get(
        string $path,
        callable|array|string $handler,
        array $middleware = [],
        ?string $page = null
    ): self {
        return $this->addHttpRoute('GET', $path, $handler, $middleware, $page);
    }

    /**
     * Adds a POST route.
     */
    public function post(
        string $path,
        callable|array|string $handler,
        array $middleware = [],
        ?string $page = null
    ): self {
        return $this->addHttpRoute('POST', $path, $handler, $middleware, $page);
    }

    /**
     * Adds a PUT route.
     */
    public function put(
        string $path,
        callable|array|string $handler,
        array $middleware = [],
        ?string $page = null
    ): self {
        return $this->addHttpRoute('PUT', $path, $handler, $middleware, $page);
    }

    /**
     * Adds a PATCH route.
     */
    public function patch(
        string $path,
        callable|array|string $handler,
        array $middleware = [],
        ?string $page = null
    ): self {
        return $this->addHttpRoute('PATCH', $path, $handler, $middleware, $page);
    }

    /**
     * Adds a DELETE route.
     */
    public function delete(
        string $path,
        callable|array|string $handler,
        array $middleware = [],
        ?string $page = null
    ): self {
        return $this->addHttpRoute('DELETE', $path, $handler, $middleware, $page);
    }

    /**
     * Adds an HTTP route with the specified method.
     *
     * @param array<callable> $middleware Array of middleware functions
     */
    private function addHttpRoute(
        string $method,
        string $path,
        callable|array|string $handler,
        array $middleware,
        ?string $page = null
    ): self {
        $this->addRoute(
            method: $method,
            group: $this->group,
            path: $path,
            handler: $handler,
            middleware: array_merge($middleware, $this->groupMiddleware),
            page: $page
        );

        $this->lastIndex = array_key_last($this->routes);
        return $this;
    }

    /**
     * Names the last added route.
     *
     * @throws InvalidArgumentException if no routes have been added or the name already exists
     */
    public function name(string $name): void
    {
        if ($this->lastIndex === null) {
            throw new InvalidArgumentException('Cannot name route: no routes have been added');
        }

        if (isset($this->routes[$name])) {
            throw new InvalidArgumentException("Route with name '{$name}' already exists");
        }

        $this->routes[$name] = $this->routes[$this->lastIndex];
        unset($this->routes[$this->lastIndex]);
    }

    /**
     * Creates a route group with prefix and middleware.
     *
     * @param array<callable> $middleware Array of middleware functions
     */
    public function group(
        string $prefix,
        callable $callback,
        array $middleware = []
    ): void {
        $previousGroup = $this->group;
        $previousMiddleware = $this->groupMiddleware;

        $this->group = $previousGroup . $prefix;
        $this->groupMiddleware = array_merge($middleware, $previousMiddleware);

        $callback($this);

        $this->group = $previousGroup;
        $this->groupMiddleware = $previousMiddleware;
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
     *     page: string|null
     * }>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}