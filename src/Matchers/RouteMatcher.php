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

    /**
     * Per-URI cache of successful matches that captured no parameters
     * (dynamic routes with optional segments collapsing to a bare form).
     *
     * @var array<string, array<string, array{
     *     handler: mixed,
     *     params: array<string, string>,
     *     middlewares: array<int, callable|string>,
     *     name: string|null
     * }>>
     */
    private array $matchCache = [];

    public function __construct()
    {
        $this->compiler = new PatternCompiler();
    }

    /**
     * @param Route[] $routes Routes already filtered by method
     * @return array{
     *     handler: mixed,
     *     params: array<string, string>,
     *     middlewares: array<int, callable|string>,
     *     name: string|null
     * }|null
     */
    public function match(array $routes, string $method, string $uri): ?array
    {
        if (isset($this->matchCache[$method][$uri])) {
            return $this->matchCache[$method][$uri];
        }

        foreach ($routes as $route) {
            $fullPath = $route->group . $route->path;

            if (!isset($this->compiledPatterns[$fullPath])) {
                $this->compiledPatterns[$fullPath] = $this->compiler->compile($fullPath);
            }

            if (!preg_match($this->compiledPatterns[$fullPath], $uri, $matches)) {
                continue;
            }

            $params = array_filter(
                $matches,
                fn($v, $k) => is_string($k) && $v !== '',
                ARRAY_FILTER_USE_BOTH
            );

            $result = [
                'handler' => $route->handler,
                'params' => $params,
                'middlewares' => $route->getMiddlewares(),
                'name' => $route->getName(),
            ];

            if (empty($params)) {
                $this->matchCache[$method][$uri] = $result;
            }

            return $result;
        }

        return null;
    }

    public function clearCache(): void
    {
        $this->compiledPatterns = [];
        $this->matchCache = [];
    }
}
