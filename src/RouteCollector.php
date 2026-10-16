<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;
use Solo\Router\Enums\HttpMethod;

/**
 * Route collector with fluent API for route configuration.
 */
final class RouteCollector extends Router
{
    private string $group = '';

    /** @var array<int, callable|string> */
    private array $groupMiddleware = [];

    /** @var array<string, mixed> */
    private array $groupAttributes = [];

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
            middlewares: $this->groupMiddleware,
            attributes: $this->groupAttributes
        ));
    }

    /**
     * Creates a route group with prefix, middleware and attributes.
     *
     * Middleware accumulates from outer to inner groups. Attributes do not: an inner group
     * or the route itself replaces the value for the same key.
     *
     * @param array<int, callable|string> $middlewares
     * @param array<string, mixed> $attributes
     * @throws InvalidArgumentException If middleware has string keys or attributes have integer keys,
     *                                  e.g. when the two arrays are passed in each other's place
     */
    public function group(
        string $prefix,
        callable $callback,
        array $middlewares = [],
        array $attributes = []
    ): void {
        foreach (array_keys($middlewares) as $key) {
            // @phpstan-ignore function.impossibleType (runtime guard: attributes passed in the middleware slot)
            if (is_string($key)) {
                throw new InvalidArgumentException(
                    "Group '$prefix' middleware has key '$key'; pass attributes as the fourth argument"
                );
            }
        }
        Route::assertAttributeKeys($attributes);

        $saved = [$this->group, $this->groupMiddleware, $this->groupAttributes];

        $this->group .= $prefix;
        $this->groupMiddleware = [...$this->groupMiddleware, ...$middlewares];
        $this->groupAttributes = array_replace($this->groupAttributes, $attributes);

        try {
            $callback($this);
        } finally {
            [$this->group, $this->groupMiddleware, $this->groupAttributes] = $saved;
        }
    }
}
