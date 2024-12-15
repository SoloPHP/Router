<?php declare(strict_types=1);

namespace Solo\Router;

use Solo\Router;
use InvalidArgumentException;

/**
 * Route collector with support for grouping and naming routes.
 */
class RouteCollector extends Router
{
    /** @var string Current group prefix */
    private string $group = '';

    /** @var array<callable> Current group middleware stack */
    private array $groupMiddleware = [];

    /** @var int|null Index of the last added route */
    private ?int $lastIndex = null;

    /**
     * Adds a GET route.
     *
     * @param string $path Route path
     * @param callable|array $handler Route handler
     * @param array<callable> $middleware Array of middleware functions
     * @return self
     */
    public function get(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addHttpRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Adds a POST route.
     *
     * @param string $path Route path
     * @param callable|array $handler Route handler
     * @param array<callable> $middleware Array of middleware functions
     * @return self
     */
    public function post(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addHttpRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Adds a PUT route.
     *
     * @param string $path Route path
     * @param callable|array $handler Route handler
     * @param array<callable> $middleware Array of middleware functions
     * @return self
     */
    public function put(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addHttpRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * Adds a PATCH route.
     *
     * @param string $path Route path
     * @param callable|array $handler Route handler
     * @param array<callable> $middleware Array of middleware functions
     * @return self
     */
    public function patch(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addHttpRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * Adds a DELETE route.
     *
     * @param string $path Route path
     * @param callable|array $handler Route handler
     * @param array<callable> $middleware Array of middleware functions
     * @return self
     */
    public function delete(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->addHttpRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Adds an HTTP route with the specified method.
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable|array $handler Route handler
     * @param array<callable> $middleware Array of middleware functions
     * @return self
     */
    private function addHttpRoute(string $method, string $path, callable|array $handler, array $middleware): self
    {
        $this->addRoute($method, $this->group, $path, $handler, array_merge($middleware, $this->groupMiddleware));
        $this->lastIndex = array_key_last($this->routes);
        return $this;
    }

    /**
     * Names the last added route.
     *
     * @param string $name Route name
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
     * @param string $prefix Group prefix
     * @param callable $callback Group definition callback
     * @param array<callable> $middleware Array of middleware functions
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousGroupPrefix = $this->group;
        $previousGroupMiddleware = $this->groupMiddleware;

        $this->group = $previousGroupPrefix . $prefix;
        $this->groupMiddleware = array_merge($middleware, $previousGroupMiddleware);

        $callback($this);

        $this->group = $previousGroupPrefix;
        $this->groupMiddleware = $previousGroupMiddleware;
    }

    /**
     * Returns all registered routes.
     *
     * @return array<string|int, array{method: string, group: string, path: string, handler: callable|array, middleware: array}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}