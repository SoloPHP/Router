<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;
use Solo\Contracts\Router\RouterInterface;
use Solo\Router\Matchers\RouteMatcher;
use Solo\Router\Enums\HttpMethod;

class Router implements RouterInterface
{
    /** @var Route[] */
    private array $routes;
    /** @var array<string, int> */
    private array $routeNameIndex = [];
    private readonly RouteMatcher $matcher;

    /** @param Route[] $routes */
    public function __construct(array $routes = [])
    {
        $this->routes = $routes;
        $this->matcher = new RouteMatcher();

        foreach ($routes as $index => $route) {
            if ($route->name !== null) {
                $this->routeNameIndex[$route->name] = $index;
            }
        }
    }

    /**
     * @param array{group?: string, middlewares?: array<int, callable>, name?: string|null} $options
     * @param callable|array{class-string, string}|string $handler
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

        $this->routes[] = new Route(
            method: $method,
            group: $options['group'] ?? '',
            path: $path,
            handler: $handler,
            middlewares: $options['middlewares'] ?? [],
            name: $name
        );

        if ($name !== null) {
            $this->routeNameIndex[$name] = count($this->routes) - 1;
        }

        // Clear pattern cache when adding a new route
        $this->matcher->clearCache();
    }

    /** @return array{handler: callable|array{class-string, string}|string, params: array<string, string>, middlewares: array<int, callable>}|false */
    public function match(string $method, string $uri): array|false
    {
        $result = $this->matcher->match($this->routes, $method, $uri);
        return $result ?? false;
    }

    /** @return Route[] */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function getRouteByName(string $name): ?Route
    {
        return isset($this->routeNameIndex[$name])
            ? $this->routes[$this->routeNameIndex[$name]]
            : null;
    }
}
