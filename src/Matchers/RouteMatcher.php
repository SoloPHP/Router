<?php

declare(strict_types=1);

namespace Solo\Router\Matchers;

use InvalidArgumentException;
use Solo\Router\Compilers\PatternCompiler;
use Solo\Router\Route;

/**
 * Matches URIs against dynamic routes in registration order.
 *
 * @internal
 */
final class RouteMatcher
{
    private readonly PatternCompiler $compiler;

    /** @var array<string, array<string, string>> Literal path prefix by method and full path, in registration order */
    private array $prefixes = [];

    /** @var array<string, array<string, string>> Compiled regex by method and full path */
    private array $regexes = [];

    /** @var array<string, array<string, Route>> Route by method and full path */
    private array $routes = [];

    public function __construct()
    {
        $this->compiler = new PatternCompiler();
    }

    /**
     * @throws InvalidArgumentException If the route path syntax is invalid
     */
    public function add(Route $route): void
    {
        $method = $route->method->value;
        $path = $route->fullPath;

        $this->regexes[$method][$path] = $this->compiler->compile($path);
        // Literal head of the path: routes whose prefix differs are skipped without running the regex
        $this->prefixes[$method][$path] = substr($path, 0, strcspn($path, '{[('));
        $this->routes[$method][$path] = $route;
    }

    public function has(string $method, string $path): bool
    {
        return isset($this->routes[$method][$path]);
    }

    /**
     * @return array{Route, array<string, string>}|null Matched route and its non-empty named parameters
     * @throws InvalidArgumentException If a custom parameter regex is invalid
     */
    public function match(string $method, string $uri): ?array
    {
        foreach ($this->prefixes[$method] ?? [] as $path => $prefix) {
            if (!str_starts_with($uri, $prefix)) {
                continue;
            }

            $regex = $this->regexes[$method][$path];
            $result = preg_match($regex, $uri, $matches);
            if ($result === 0) {
                continue;
            }
            if ($result === false) {
                throw new InvalidArgumentException(
                    "Route '{$method} {$path}' compiles to an invalid regular expression: {$regex}"
                );
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key) && $value !== '') {
                    $params[$key] = $value;
                }
            }

            return [$this->routes[$method][$path], $params];
        }

        return null;
    }
}
