<?php

declare(strict_types=1);

namespace Solo\Router\Compilers;

use InvalidArgumentException;
use Solo\Router\RouteDefinition;
use Solo\Router\Router;

/**
 * @internal
 * @phpstan-import-type RouteData from Router
 */
final class RouteCompiler
{
    /** @param list<RouteDefinition> $definitions */
    public function compile(array $definitions): Router
    {
        $compiler = new PatternCompiler();
        $routes = $names = $static = $params = $keys = $patterns = $registered = [];

        foreach ($definitions as $definition) {
            $method = $definition->method->value;
            $path = $definition->fullPath;
            $name = $definition->getName();

            // NUL marks groups in pattern keys; static paths follow the same rule
            if (str_contains($path, PatternCompiler::MARKER)) {
                throw new InvalidArgumentException("Route '$method $path' contains a NUL byte");
            }
            if (isset($registered[$method][$path])) {
                throw new InvalidArgumentException("Route '$method $path' is already registered");
            }
            if ($name !== null && isset($names[$name])) {
                throw new InvalidArgumentException("Route with name '$name' already exists");
            }

            $routeId = count($routes);
            $routes[] = [
                $method,
                $path,
                $definition->handler,
                $definition->getMiddlewares(),
                $name,
                $definition->getAttributes(),
            ];
            $registered[$method][$path] = true;
            if ($name !== null) {
                $names[$name] = $routeId;
            }

            if (strpbrk($path, PatternCompiler::SPECIAL_CHARS) === false) {
                $static[$path][$method] = $routeId;
                continue;
            }

            try {
                [$key, $params[$routeId]] = $patterns[$path] ??= $compiler->compile($path);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("Route '$method $path' {$e->getMessage()}", 0, $e);
            }
            $earlier = $keys[$method][$key] ?? null;
            if ($earlier !== null) {
                throw new InvalidArgumentException(
                    "Route '$method $path' can never match: '$method {$routes[$earlier][1]}' has the same pattern"
                );
            }
            $keys[$method][$key] = $routeId;
        }

        $dynamic = [];
        foreach ($keys as $method => $methodKeys) {
            $dynamic[$method] = $this->combine($compiler, $methodKeys, $routes);
        }

        return new Router($routes, $names, $static, $dynamic, $params);
    }

    /**
     * Combines the routes of one method into a single pattern, splitting it only when
     * PCRE rejects it, e.g. as too large.
     *
     * @param array<string, int> $keys Route ID by key, in registration order
     * @param list<RouteData> $routes
     * @return list<string>
     * @throws InvalidArgumentException If PCRE rejects the pattern of a single route, e.g. as too large
     */
    private function combine(PatternCompiler $compiler, array $keys, array $routes): array
    {
        $tree = new PrefixTree($compiler);
        foreach ($keys as $key => $routeId) {
            $tree->add((string) $key, $routeId);
        }
        $regex = $tree->regex();
        error_clear_last();
        if (@preg_match($regex, '') !== false) {
            return [$regex];
        }
        if (count($keys) === 1) {
            [$method, $path] = $routes[reset($keys)];
            $reason = error_get_last()['message'] ?? preg_last_error_msg();
            throw new InvalidArgumentException("Route '$method $path' cannot be compiled: $reason");
        }

        $half = intdiv(count($keys), 2);

        return [
            ...$this->combine($compiler, array_slice($keys, 0, $half, true), $routes),
            ...$this->combine($compiler, array_slice($keys, $half, null, true), $routes),
        ];
    }
}
