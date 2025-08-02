<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;

/**
 * Base router class for handling HTTP routes.
 */
class Router implements RouterInterface
{
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param array<array{
     *     method: string,
     *     group: string,
     *     path: string,
     *     handler: callable|array|string,
     *     middleware: array<callable>,
     *     page: string|null
     * }> $routes
     */
    public function __construct(
        protected array $routes = []
    ) {
    }

    /**
     * Adds a new route to the router.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $group Route group prefix
     * @param string $path Route path
     * @param callable|array|string $handler
     * @param array<callable> $middleware Array of middleware functions
     * @throws InvalidArgumentException if HTTP method is not supported
     */
    public function addRoute(
        string $method,
        string $group,
        string $path,
        callable|array|string $handler,
        array $middleware = [],
        ?string $page = null
    ): void {
        $method = strtoupper($method);

        if (!in_array($method, self::HTTP_METHODS, true)) {
            throw new InvalidArgumentException("Unsupported HTTP method: {$method}");
        }

        $this->routes[] = [
            'method' => $method,
            'group' => $group,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
            'page' => $page
        ];
    }

    /**
     * Matches the requested method and URL against registered routes.
     *
     * @param string $requestMethod HTTP method of the request
     * @param string $url Requested URL
     * @return array{
     *     method: string,
     *     group: string,
     *     handler: callable|array|string,
     *     args: array<string, string>,
     *     middleware: array<callable>,
     *     page: string|null
     * }|false
     */
    public function matchRoute(string $requestMethod, string $url): array|false
    {
        $requestMethod = strtoupper($requestMethod);

        foreach ($this->routes as $route) {
            if ($requestMethod !== $route['method']) {
                continue;
            }

            $pattern = $this->buildPattern($route['group'] . $route['path']);

            if (preg_match($pattern, $url, $matches)) {
                $args = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return [
                    'method' => $route['method'],
                    'group' => $route['group'],
                    'handler' => $route['handler'],
                    'args' => $args,
                    'middleware' => $route['middleware'],
                    'page' => $route['page']
                ];
            }
        }

        return false;
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

    /**
     * Builds a regular expression pattern for route matching.
     */
    private function buildPattern(string $path): string
    {
        $pattern = str_replace('/', '\/', $path);
        $pattern = preg_replace('/\[(?![^{]*})/', '(?:', $pattern);
        $pattern = preg_replace('/](?![^{]*})/', ')?', $pattern);
        $pattern = preg_replace('/{(\w+)(:([^}]+))?}/', '(?<$1>[^\/]+)', $pattern);

        return '/^' . $pattern . '$/';
    }
}
