<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;
use Solo\Contracts\Router\RouterInterface;
use Solo\Router\Matchers\RouteMatcher;
use Solo\Router\Enums\HttpMethod;

/**
 * High-performance router with optimized route matching.
 */
class Router implements RouterInterface
{
    /** @var array<string, Route> */
    private array $staticRoutes = [];

    /** @var array<string, Route[]> */
    private array $dynamicRoutes = [];

    /** @var Route[] */
    private array $routes = [];

    /** @var array<string, Route>|null */
    private ?array $routeNameIndex = null;

    /**
     * Regex matcher for dynamic and complex routes.
     */
    private readonly RouteMatcher $regexMatcher;

    /**
     * Create a new Router instance.
     *
     * @param Route[] $routes Optional initial routes
     */
    public function __construct(array $routes = [])
    {
        $this->regexMatcher = new RouteMatcher();

        foreach ($routes as $route) {
            $this->addRouteObject($route);
        }
    }

    /**
     * Add a new route to the router.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path Route path pattern
     * @param mixed $handler Route handler (callable, controller, etc.)
     * @param array{group?: string, middlewares?: array<int, callable|string>, name?: string|null} $options
     * @throws InvalidArgumentException If method is invalid
     */
    public function addRoute(
        string $method,
        string $path,
        mixed $handler,
        array $options = []
    ): Route {
        $httpMethod = HttpMethod::tryFrom(strtoupper($method))
            ?? throw new InvalidArgumentException("Unsupported HTTP method: $method");

        $route = new Route(
            method: $httpMethod,
            group: $options['group'] ?? '',
            path: $path,
            handler: $handler,
            middlewares: $options['middlewares'] ?? [],
            name: $options['name'] ?? null
        );

        $this->addRouteObject($route);

        return $route;
    }

    /**
     * Add a Route object to the router.
     */
    private function addRouteObject(Route $route): void
    {
        $this->routes[] = $route;
        $this->routeNameIndex = null;

        $method = $route->method->value;
        $fullPath = $route->group . $route->path;

        if ($this->isStaticRoute($fullPath)) {
            $this->staticRoutes[$method . ':' . $fullPath] = $route;
        } else {
            $this->dynamicRoutes[$method][] = $route;
        }
    }

    /**
     * Check if route is static (no parameters, optional segments, or regex groups).
     */
    private function isStaticRoute(string $path): bool
    {
        return !str_contains($path, '{')
            && !str_contains($path, '[')
            && !str_contains($path, '(');
    }

    /**
     * Match a request against registered routes.
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return array{
     *     handler: callable|array{class-string, string}|string,
     *     params: array<string, string>,
     *     middlewares: array<int, callable|string>,
     *     name: string|null
     * }|false
     */
    public function match(string $method, string $uri): array|false
    {
        $method = strtoupper($method);

        // HEAD falls back to GET if no explicit HEAD route exists (RFC 7231)
        $methods = ($method === 'HEAD') ? ['HEAD', 'GET'] : [$method];

        foreach ($methods as $m) {
            $staticKey = $m . ':' . $uri;
            if (isset($this->staticRoutes[$staticKey])) {
                $route = $this->staticRoutes[$staticKey];
                return [
                    'handler' => $route->handler,
                    'params' => [],
                    'middlewares' => $route->getMiddlewares(),
                    'name' => $route->getName(),
                ];
            }

            if (isset($this->dynamicRoutes[$m])) {
                $result = $this->regexMatcher->match($this->dynamicRoutes[$m], $m, $uri);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return false;
    }

    /**
     * Get all registered routes.
     *
     * @return Route[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get a route by its name.
     *
     * @param string $name Route name
     */
    public function getRouteByName(string $name): ?Route
    {
        $this->buildNameIndex();
        return $this->routeNameIndex[$name] ?? null;
    }

    /**
     * Build name index lazily.
     *
     * @throws InvalidArgumentException If duplicate route names exist
     */
    private function buildNameIndex(): void
    {
        if ($this->routeNameIndex !== null) {
            return;
        }

        $this->routeNameIndex = [];
        foreach ($this->routes as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }
            if (isset($this->routeNameIndex[$name])) {
                throw new InvalidArgumentException("Route with name '$name' already exists");
            }
            $this->routeNameIndex[$name] = $route;
        }
    }
}
