<?php

declare(strict_types=1);

namespace Solo\Router\Matchers;

use InvalidArgumentException;
use Solo\Router\Compilers\PatternCompiler;
use Solo\Router\Route;

/**
 * Matches URIs against dynamic routes in registration order.
 *
 * A path registered for several methods is compiled once and shares its regex.
 *
 * @internal
 */
final class RouteMatcher
{
    private readonly PatternCompiler $compiler;

    /** @var array<string, string> Literal path prefix by full path */
    private array $prefixes = [];

    /** @var array<string, string> Compiled regex by full path */
    private array $regexes = [];

    /** @var array<string, array<string, Route>> Route by method and full path, in registration order */
    private array $routes = [];

    /** @var array<string, array<string, true>> Methods registered for each full path */
    private array $methods = [];

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

        if (!isset($this->regexes[$path])) {
            $this->regexes[$path] = $this->compiler->compile($path);
            // Literal head of the path: routes whose prefix differs are skipped without running the regex
            $this->prefixes[$path] = substr($path, 0, strcspn($path, '{[('));
        }

        $this->routes[$method][$path] = $route;
        $this->methods[$path][$method] = true;
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
        foreach ($this->routes[$method] ?? [] as $path => $route) {
            if (!str_starts_with($uri, $this->prefixes[$path])) {
                continue;
            }

            $result = preg_match($this->regexes[$path], $uri, $matches);
            if ($result === 1) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key) && $value !== '') {
                        $params[$key] = $value;
                    }
                }

                return [$route, $params];
            }
            if ($result === false) {
                throw $this->invalidRegex($method, $path);
            }
        }

        return null;
    }

    /**
     * Methods that have a dynamic route matching the URI; each path's regex runs at most once.
     *
     * @return array<string, true>
     * @throws InvalidArgumentException If a custom parameter regex is invalid
     */
    public function methodsMatching(string $uri): array
    {
        $methods = [];
        foreach ($this->methods as $path => $pathMethods) {
            if (!str_starts_with($uri, $this->prefixes[$path])) {
                continue;
            }

            $result = preg_match($this->regexes[$path], $uri);
            if ($result === 1) {
                $methods += $pathMethods;
            } elseif ($result === false) {
                throw $this->invalidRegex((string) array_key_first($pathMethods), $path);
            }
        }

        return $methods;
    }

    private function invalidRegex(string $method, string $path): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Route '{$method} {$path}' compiles to an invalid regular expression: {$this->regexes[$path]}"
        );
    }
}
