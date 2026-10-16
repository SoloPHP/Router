<?php

declare(strict_types=1);

namespace Solo\Router;

use Closure;
use InvalidArgumentException;
use Solo\Contracts\Router\RouterInterface;
use Solo\Router\Matchers\RouteMatcher;
use Solo\Router\Enums\HttpMethod;

/**
 * High-performance router with optimized route matching.
 */
class Router implements RouterInterface
{
    /** @var array<string, array<string, Route>> Static routes by method and path */
    private array $staticRoutes = [];

    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $namedRoutes = [];

    /**
     * Regex matcher for dynamic and complex routes.
     */
    private readonly RouteMatcher $regexMatcher;

    /** @var Closure(Route, string): void Shared by all routes to keep the name index in sync */
    private readonly Closure $renameListener;

    /**
     * Create a new Router instance.
     *
     * @param Route[] $routes Optional initial routes
     * @throws InvalidArgumentException If a route is invalid or already registered
     */
    public function __construct(array $routes = [])
    {
        $this->regexMatcher = new RouteMatcher();
        $this->renameListener = $this->renameRoute(...);

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
     * @throws InvalidArgumentException If the method is unsupported, the path is invalid,
     *                                  or the route or its name is already registered
     */
    public function addRoute(
        string $method,
        string $path,
        mixed $handler,
        array $options = []
    ): Route {
        $httpMethod = HttpMethod::tryFrom(strtoupper($method))
            ?? throw new InvalidArgumentException("Unsupported HTTP method: $method");

        return $this->addRouteObject(new Route(
            method: $httpMethod,
            group: $options['group'] ?? '',
            path: $path,
            handler: $handler,
            middlewares: $options['middlewares'] ?? [],
            name: $options['name'] ?? null
        ));
    }

    /**
     * Add a Route object to the router.
     *
     * @throws InvalidArgumentException If the path is invalid or the route or its name is already registered
     */
    protected function addRouteObject(Route $route): Route
    {
        $method = $route->method->value;
        $path = $route->fullPath;
        $name = $route->getName();

        if (isset($this->staticRoutes[$method][$path]) || $this->regexMatcher->has($method, $path)) {
            throw new InvalidArgumentException("Route '$method $path' is already registered");
        }
        if ($name !== null) {
            $this->assertNameAvailable($name, $route);
        }

        if (strpbrk($path, '{}[]()') === false) {
            $this->staticRoutes[$method][$path] = $route;
        } else {
            $this->regexMatcher->add($route);
        }

        $this->routes[] = $route;
        if ($name !== null) {
            $this->namedRoutes[$name] = $route;
        }
        $route->onNameChange($this->renameListener);

        return $route;
    }

    /**
     * Match a request against registered routes.
     *
     * Static routes take precedence over dynamic ones; dynamic routes are tried in registration order.
     *
     * @param string $method HTTP method
     * @param string $uri Request URI path
     * @return array{
     *     handler: callable|array{class-string, string}|string,
     *     params: array<string, string>,
     *     middlewares: array<int, callable|string>,
     *     name: string|null
     * }|false
     * @throws InvalidArgumentException If a custom parameter regex is invalid
     */
    public function match(string $method, string $uri): array|false
    {
        $found = $this->findRoute(strtoupper($method), $uri);
        if ($found === null) {
            return false;
        }

        [$route, $params] = $found;

        return [
            'handler' => $route->handler,
            'params' => $params,
            'middlewares' => $route->getMiddlewares(),
            'name' => $route->getName(),
        ];
    }

    /**
     * Get the HTTP methods that have a route matching the URI.
     *
     * Use it to tell 405 Method Not Allowed from 404 and to fill the Allow header.
     *
     * @return list<string>
     */
    public function getAllowedMethods(string $uri): array
    {
        $allowed = [];
        foreach (HttpMethod::cases() as $method) {
            if ($this->findRoute($method->value, $uri) !== null) {
                $allowed[] = $method->value;
            }
        }

        return $allowed;
    }

    /**
     * Get all registered routes.
     *
     * @return list<Route>
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
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * @return array{Route, array<string, string>}|null
     */
    private function findRoute(string $method, string $uri): ?array
    {
        // HEAD falls back to GET if no explicit HEAD route exists (RFC 7231)
        foreach ($method === 'HEAD' ? ['HEAD', 'GET'] : [$method] as $m) {
            if (isset($this->staticRoutes[$m][$uri])) {
                return [$this->staticRoutes[$m][$uri], []];
            }

            $found = $this->regexMatcher->match($m, $uri);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Keep the name index in sync when a registered route is renamed.
     *
     * @throws InvalidArgumentException If another route already has the name
     */
    private function renameRoute(Route $route, string $name): void
    {
        $this->assertNameAvailable($name, $route);

        $previous = $route->getName();
        if ($previous !== null) {
            unset($this->namedRoutes[$previous]);
        }
        $this->namedRoutes[$name] = $route;
    }

    /**
     * @throws InvalidArgumentException If another route already has the name
     */
    private function assertNameAvailable(string $name, Route $route): void
    {
        $owner = $this->namedRoutes[$name] ?? null;
        if ($owner !== null && $owner !== $route) {
            throw new InvalidArgumentException("Route with name '$name' already exists");
        }
    }
}
