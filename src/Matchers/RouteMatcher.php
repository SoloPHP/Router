<?php

declare(strict_types=1);

namespace Solo\Router\Matchers;

use Solo\Router\Compilers\PatternCompiler;
use Solo\Router\Route;

final class RouteMatcher
{
    private readonly PatternCompiler $compiler;
    /** @var array<int, string> */
    private array $compiledPatterns = [];

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

        foreach ($routes as $index => $route) {
            if ($method !== $route->method->value) {
                continue;
            }

            if (!isset($this->compiledPatterns[$index])) {
                $fullPath = $route->group . $route->path;
                $this->compiledPatterns[$index] = $this->compiler->compile($fullPath);
            }

            if (preg_match($this->compiledPatterns[$index], $uri, $matches)) {
                $params = array_filter(
                    $matches,
                    fn($v, $k) => is_string($k) && $v !== '',
                    ARRAY_FILTER_USE_BOTH
                );

                return [
                    'handler' => $route->handler,
                    'params' => $params,
                    'middlewares' => $route->middlewares,
                ];
            }
        }

        return null;
    }

    public function clearCache(): void
    {
        $this->compiledPatterns = [];
    }
}
