<?php

declare(strict_types=1);

namespace Solo\Router\Matchers;

use Solo\Router\Compilers\PatternCompiler;
use Solo\Router\Route;

final class RouteMatcher
{
    private readonly PatternCompiler $compiler;
    /** @var array<string, string> */
    private array $compiledPatterns = [];
    /** @var array<string, array<string, array{handler: mixed, params: array<string, string>, middlewares: array<int, callable>}>> */
    private array $staticRoutes = [];
    /** @var array<string, Route[]> */
    private array $routesByMethod = [];
    private bool $routesIndexed = false;

    public function __construct()
    {
        $this->compiler = new PatternCompiler();
    }

    /**
     * @param Route[] $routes
     * @return array{handler: mixed, params: array<string, string>, middlewares: array<int, callable>}|null
     */
    public function match(array $routes, string $method, string $uri): ?array
    {
        $method = strtoupper($method);

        // Index routes by method on first call
        if (!$this->routesIndexed) {
            $this->indexRoutes($routes);
        }

        // Check static routes cache first (fastest path)
        if (isset($this->staticRoutes[$method][$uri])) {
            return $this->staticRoutes[$method][$uri];
        }

        // Use indexed routes by method to avoid checking all routes
        $methodRoutes = $this->routesByMethod[$method] ?? [];

        // Lazy compilation - only compile patterns when needed
        foreach ($methodRoutes as $route) {
                $fullPath = $route->group . $route->path;
            $patternKey = $fullPath;

            // Check if pattern is already compiled and cached
            if (!isset($this->compiledPatterns[$patternKey])) {
                $this->compiledPatterns[$patternKey] = $this->compiler->compile($fullPath);
            }

            if (preg_match($this->compiledPatterns[$patternKey], $uri, $matches)) {
                $params = array_filter(
                    $matches,
                    fn($v, $k) => is_string($k) && $v !== '',
                    ARRAY_FILTER_USE_BOTH
                );

                $result = [
                    'handler' => $route->handler,
                    'params' => $params,
                    'middlewares' => $route->middlewares,
                ];

                // Cache static routes for future lookups
                if (empty($params)) {
                    $this->staticRoutes[$method][$uri] = $result;
                }

                return $result;
            }
        }

        return null;
    }

    /**
     * @param Route[] $routes
     */
    private function indexRoutes(array $routes): void
    {
        foreach ($routes as $route) {
            $method = $route->method->value;
            if (!isset($this->routesByMethod[$method])) {
                $this->routesByMethod[$method] = [];
            }
            $this->routesByMethod[$method][] = $route;
        }
        $this->routesIndexed = true;
    }

    public function clearCache(): void
    {
        $this->compiledPatterns = [];
        $this->staticRoutes = [];
        $this->routesByMethod = [];
        $this->routesIndexed = false;
    }
}
