<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;
use Solo\Router\Compilers\RouteCompiler;
use Solo\Router\Enums\HttpMethod;

/**
 * Route collector with fluent API for route configuration.
 *
 * @phpstan-type RouteOptions array{
 *     group?: string,
 *     middlewares?: array<int, callable|string>,
 *     name?: string|null,
 *     attributes?: array<string, mixed>
 * }
 */
final class RouteCollector
{
    /** @var list<RouteDefinition> */
    private array $routes = [];

    private string $group = '';

    /** @var list<callable|string> */
    private array $groupMiddleware = [];

    /** @var array<string, mixed> */
    private array $groupAttributes = [];

    /**
     * Adds a GET route.
     *
     * @param mixed $handler Opaque application handler; the router does not execute it
     */
    public function get(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(HttpMethod::GET, $path, $handler);
    }

    /**
     * Adds a HEAD route.
     *
     * @param mixed $handler Opaque application handler; the router does not execute it
     */
    public function head(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(HttpMethod::HEAD, $path, $handler);
    }

    /**
     * Adds a POST route.
     *
     * @param mixed $handler Opaque application handler; the router does not execute it
     */
    public function post(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(HttpMethod::POST, $path, $handler);
    }

    /**
     * Adds a PUT route.
     *
     * @param mixed $handler Opaque application handler; the router does not execute it
     */
    public function put(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(HttpMethod::PUT, $path, $handler);
    }

    /**
     * Adds a PATCH route.
     *
     * @param mixed $handler Opaque application handler; the router does not execute it
     */
    public function patch(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(HttpMethod::PATCH, $path, $handler);
    }

    /**
     * Adds a DELETE route.
     *
     * @param mixed $handler Opaque application handler; the router does not execute it
     */
    public function delete(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(HttpMethod::DELETE, $path, $handler);
    }

    /**
     * Adds an OPTIONS route.
     *
     * @param mixed $handler Opaque application handler; the router does not execute it
     */
    public function options(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(HttpMethod::OPTIONS, $path, $handler);
    }

    /**
     * Declares a route using the active group and any route-specific options.
     *
     * @param mixed $handler Opaque application handler; the router does not execute it
     * @param RouteOptions $options Group prefix, middlewares, name and attributes
     * @throws InvalidArgumentException If the method is unsupported, middleware has string keys
     *                                  or attribute keys are integers
     */
    public function addRoute(
        string $method,
        string $path,
        mixed $handler,
        array $options = []
    ): RouteDefinition {
        $httpMethod = HttpMethod::tryFrom(strtoupper($method))
            ?? throw new InvalidArgumentException("Unsupported HTTP method: $method");

        return $this->add($httpMethod, $path, $handler, $options);
    }

    /**
     * @return list<RouteDefinition>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Compiles a new snapshot. Later changes to this collector do not alter the router.
     */
    public function build(): Router
    {
        return (new RouteCompiler())->compile($this->routes);
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
        self::assertMiddlewareKeys($middlewares, "Group '$prefix'", 'as the fourth argument');
        RouteDefinition::assertAttributeKeys($attributes);

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

    /**
     * @param RouteOptions $options
     * @throws InvalidArgumentException If middleware has string keys or attribute keys are integers
     */
    private function add(HttpMethod $method, string $path, mixed $handler, array $options = []): RouteDefinition
    {
        $group = $this->group;
        $middlewares = $this->groupMiddleware;
        $attributes = $this->groupAttributes;
        // Most routes have no options: they take the group's values as they are
        if ($options !== []) {
            $group .= $options['group'] ?? '';
            if (isset($options['middlewares'])) {
                self::assertMiddlewareKeys(
                    $options['middlewares'],
                    "Route '{$method->value} {$group}{$path}'",
                    "in the 'attributes' option"
                );
                $middlewares = [...$middlewares, ...$options['middlewares']];
            }
            $attributes = array_replace($attributes, $options['attributes'] ?? []);
        }

        $route = new RouteDefinition(
            method: $method,
            group: $group,
            path: $path,
            handler: $handler,
            middlewares: $middlewares,
            name: $options['name'] ?? null,
            attributes: $attributes
        );
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Middleware lists are positional; a string key usually means attributes in the wrong place.
     *
     * @param array<mixed> $middlewares
     * @throws InvalidArgumentException If a key is a string
     */
    private static function assertMiddlewareKeys(array $middlewares, string $owner, string $attributesHint): void
    {
        foreach (array_keys($middlewares) as $key) {
            if (is_string($key)) {
                throw new InvalidArgumentException(
                    "$owner middleware has key '$key'; pass attributes $attributesHint"
                );
            }
        }
    }
}
