<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use PHPUnit\Framework\TestCase;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;
use Solo\Router\Results\NotFound;
use Solo\Router\RouteCollector;
use Solo\Router\Router;

/**
 * Combined patterns must match exactly like routes tried one by one in registration order.
 * Random tables of overlapping literals, constraints, optional segments and catch-alls are
 * compared with a reference built from single-route routers.
 */
final class DifferentialMatchingTest extends TestCase
{
    private const array SEGMENTS = [
        'a', 'b', 'ab', 'users', '0', '1', '', 'en',
        '{%s}', '{%s}', '{%s:\d+}', '{%s:[a-z]{2}}', '{%s:en|ru}', '{%s:.*}', '{%s:[a-z0-9-]+}',
        'x{%s}', '{%s}.json', '(a|b)',
    ];
    private const array VALUES = ['42', 'en', 'ab', 'a', 'x/y', '', 'users', '7', 'a.json', 'x1'];

    public function testCombinedPatternsMatchLikeRoutesTriedOneByOne(): void
    {
        mt_srand(20261025);
        for ($case = 0; $case < 120; $case++) {
            $routes = $this->randomTable();
            $built = $this->build($routes);
            $singles = array_map(fn(array $route): Router => $this->build([$route]), $routes);

            foreach ($this->randomUris($routes) as $uri) {
                $allowed = $this->expectedAllowedMethods($routes, $singles, $uri);
                // A router restored from its export must behave exactly like the built one
                foreach ([$built, Router::fromArray($built->toArray())] as $router) {
                    $this->assertSame($allowed, $router->getAllowedMethods($uri), "Allowed methods for $uri");
                    foreach (['GET', 'POST', 'HEAD'] as $method) {
                        $this->assertSame(
                            $this->expected($routes, $singles, $method, $uri, $allowed),
                            $this->actual($router->resolve($method, $uri)),
                            "$method $uri, routes: " . json_encode($routes)
                        );
                    }
                }
            }
        }
    }

    /**
     * @param list<array{string, string}> $routes
     * @param list<Router> $singles
     * @param list<string> $allowed
     * @return list<mixed>
     */
    private function expected(array $routes, array $singles, string $method, string $uri, array $allowed): array
    {
        foreach ($method === 'HEAD' ? ['HEAD', 'GET'] : [$method] as $candidate) {
            // Static routes precede dynamic routes of the same method.
            foreach ($routes as $id => [$routeMethod, $path]) {
                if ($routeMethod === $candidate && $path === $uri && !$this->isDynamic($path)) {
                    return ['matched', $id, []];
                }
            }
            foreach ($routes as $id => [$routeMethod, $path]) {
                if ($routeMethod === $candidate && $this->isDynamic($path)) {
                    $result = $singles[$id]->resolve($candidate, $uri);
                    if ($result instanceof Matched) {
                        return ['matched', $id, $result->params];
                    }
                }
            }
        }

        return $allowed === [] ? ['not found'] : ['not allowed', $allowed];
    }

    /**
     * @param list<array{string, string}> $routes
     * @param list<Router> $singles
     * @return list<string>
     */
    private function expectedAllowedMethods(array $routes, array $singles, string $uri): array
    {
        $methods = [];
        foreach ($routes as $id => [$method]) {
            if ($singles[$id]->resolve($method, $uri) instanceof Matched) {
                $methods[$method] = true;
            }
        }
        if (isset($methods['GET'])) {
            $methods['HEAD'] = true;
        }

        return array_values(array_filter(['GET', 'HEAD', 'POST'], static fn(string $m): bool => isset($methods[$m])));
    }

    /** @return list<mixed> */
    private function actual(Matched|NotFound|MethodNotAllowed $result): array
    {
        return match (true) {
            $result instanceof Matched => ['matched', $result->route->handler, $result->params],
            $result instanceof MethodNotAllowed => ['not allowed', $result->allowedMethods],
            default => ['not found'],
        };
    }

    /** @param list<array{string, string}> $routes */
    private function build(array $routes): Router
    {
        $builder = new RouteCollector();
        foreach ($routes as $id => [$method, $path]) {
            $builder->addRoute($method, $path, $id);
        }

        return $builder->build();
    }

    private function isDynamic(string $path): bool
    {
        return strpbrk($path, '{}[]()') !== false;
    }

    /**
     * Skips a route whose method and pattern equal an earlier one: build() rejects it.
     *
     * @return list<array{string, string}> Method and path in registration order
     */
    private function randomTable(): array
    {
        $routes = $seen = [];
        for ($i = mt_rand(1, 25); $i > 0; $i--) {
            $method = self::pick(['GET', 'GET', 'POST', 'HEAD']);
            $path = $this->randomPath();
            $canonical = preg_replace(['/\{(p\d+):\[\^\/\]\+\}/', '/\{p\d+/'], ['{$1}', '{p'], $path);
            if (!isset($seen[$method][$canonical])) {
                $seen[$method][$canonical] = true;
                $routes[] = [$method, $path];
            }
        }

        return $routes;
    }

    private function randomPath(): string
    {
        $path = '';
        $param = 0;
        for ($i = mt_rand(1, 4); $i > 0; $i--) {
            if (mt_rand(0, 9) === 0) {
                $path .= sprintf(self::pick(['[/{%s}]', '[/a]', '[/{%s:[a-z]{2}}]']), 'p' . $param++);
            }
            $path .= '/' . sprintf(self::pick(self::SEGMENTS), 'p' . $param++);
        }
        if (mt_rand(0, 9) === 0) {
            $path .= sprintf(self::pick(['[/{%s}]', '[/]', '[.{%s:json|xml}]']), 'p' . $param);
        }

        return $path;
    }

    /**
     * @param list<array{string, string}> $routes
     * @return list<string>
     */
    private function randomUris(array $routes): array
    {
        $uris = [];
        for ($i = 0; $i < 25; $i++) {
            if (mt_rand(0, 4) === 0) {
                $segments = [];
                for ($n = mt_rand(0, 4); $n > 0; $n--) {
                    $segments[] = self::pick(['a', 'b', 'ab', 'users', '0', '1', '', 'en', 'ru', '42', 'a.json', 'x1']);
                }
                $uris[] = '/' . implode('/', $segments);
                continue;
            }

            // Instantiate a registered path: keep or drop optional parts, fill in parameters.
            $uri = self::pick($routes)[1];
            $optional = static fn(array $m): string => mt_rand(0, 1) ? $m[1] : '';
            for ($n = 0; $n < 2; $n++) {
                $uri = (string) preg_replace_callback('/\[([^\[\]]*)]/', $optional, $uri);
            }
            $value = static fn(): string => self::pick(self::VALUES);
            $uri = (string) preg_replace_callback('/\{[^{}]*(?:\{[^{}]*}[^{}]*)*}/', $value, $uri);
            $uri = str_replace('(a|b)', self::pick(['a', 'b', 'c']), $uri);
            $uris[] = mt_rand(0, 5) === 0 ? $uri . self::pick(['/', '/a', 'x', '/42']) : $uri;
        }

        return $uris;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T
     */
    private static function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }
}
