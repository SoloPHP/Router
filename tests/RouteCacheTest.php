<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Solo\Router\Enums\HttpMethod;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;
use Solo\Router\Results\NotFound;
use Solo\Router\RouteCache;
use Solo\Router\RouteCollector;
use Solo\Router\Router;
use stdClass;

final class RouteCacheTest extends TestCase
{
    private const array URIS = ['', '/', '/en', '/users', '/users/42', '/en/users/me', '/files/a/b', '/admin/reports', '/x'];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/solo-router-cache-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testExportedRouterResolvesExactlyLikeTheBuiltOne(): void
    {
        $built = $this->routes()->build();
        $restored = Router::fromArray($built->toArray());

        $this->assertSameOutcomes($built, $restored);
        $this->assertEquals($built->getRoutes(), $restored->getRoutes());
        $this->assertEquals($built->getRouteByName('users.show'), $restored->getRouteByName('users.show'));
        $this->assertNull($restored->getRouteByName('missing'));
    }

    public function testRestoredRoutesAreCreatedOnceAndShared(): void
    {
        $router = Router::fromArray($this->routes()->build()->toArray());

        $route = $router->getRouteByName('users.show');
        $result = $router->resolve('GET', '/users/42');
        $this->assertInstanceOf(Matched::class, $result);
        $this->assertSame($route, $result->route);
        $this->assertContains($route, $router->getRoutes());
    }

    public function testCacheFileStoresEnumsAndNestedArrays(): void
    {
        $routes = new RouteCollector();
        $routes->get('/report', ['App\Reports', 'show'])
            ->middleware('auth', 'log')
            ->attribute('method', HttpMethod::POST)
            ->attribute('limits', ['burst' => [10, 20], 'ratio' => 0.5, 'strict' => true, 'note' => null]);
        $file = $this->directory . '/routes.php';
        (new RouteCache($file))->save($routes->build());

        // A class constant in the file would keep OPcache from storing the route table as a constant
        $this->assertStringNotContainsString('HttpMethod::POST', (string) file_get_contents($file));
        $loaded = (new RouteCache($file))->load($this->failOnBuild(...));
        foreach ([$loaded, Router::fromArray($loaded->toArray())] as $router) {
            $route = $router->getRoutes()[0];
            $this->assertSame(['App\Reports', 'show'], $route->handler);
            $this->assertSame(['auth', 'log'], $route->middlewares);
            $this->assertSame(HttpMethod::POST, $route->getAttribute('method'));
            $this->assertSame(
                ['burst' => [10, 20], 'ratio' => 0.5, 'strict' => true, 'note' => null],
                $route->getAttribute('limits')
            );
        }
    }

    public function testOnlyRoutesWithEnumsAreSerialized(): void
    {
        $routes = new RouteCollector();
        $routes->get('/a', 'a')->attribute('method', HttpMethod::POST);
        $routes->get('/b', 'b')->attribute('area', 'admin');
        $routes->get('/c', 'c')->attribute('method', HttpMethod::PUT);
        $exported = $routes->build()->toArray();

        // Serializing costs every request that first uses the route, so plain routes stay arrays
        $this->assertSame(['string', 'array', 'string'], array_map(get_debug_type(...), $exported['routes']));
        $file = $this->directory . '/routes.php';
        (new RouteCache($file))->save(Router::fromArray($exported));
        $this->assertStringNotContainsString('HttpMethod::', (string) file_get_contents($file));
        $restored = (new RouteCache($file))->load($this->failOnBuild(...));
        $this->assertSame(HttpMethod::PUT, $restored->getRoutes()[2]->getAttribute('method'));
    }

    #[DataProvider('unexportableRoutes')]
    public function testValuesThatCannotBeExportedAreRejected(callable $define, string $message): void
    {
        $routes = new RouteCollector();
        $define($routes);
        $router = $routes->build();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);
        $router->toArray();
    }

    public static function unexportableRoutes(): iterable
    {
        yield 'closure handler' => [
            static fn(RouteCollector $r) => $r->get('/a', static fn() => 'a'),
            "Route 'GET /a' cannot be exported: its handler contains Closure; only scalars, null, arrays and enums can be exported",
        ];
        yield 'object in a handler array' => [
            static fn(RouteCollector $r) => $r->post('/b/{id}', [new stdClass(), 'run']),
            "Route 'POST /b/{id}' cannot be exported: its handler contains stdClass",
        ];
        yield 'closure middleware' => [
            static fn(RouteCollector $r) => $r->get('/c', 'c')->middleware('auth', static fn() => null),
            "Route 'GET /c' cannot be exported: its middleware contains Closure",
        ];
        yield 'nested object attribute' => [
            static fn(RouteCollector $r) => $r->get('/d', 'd')->attribute('policy', ['rules' => [new stdClass()]]),
            "Route 'GET /d' cannot be exported: its attributes contains stdClass",
        ];
        yield 'deeply nested attribute' => [
            static fn(RouteCollector $r) => $r->get('/e', 'e')
                ->attribute('tree', array_reduce(range(1, 63), static fn(array $tree): array => [$tree], [])),
            "Route 'GET /e' cannot be exported: its attributes contains an array nested too deeply",
        ];
        yield 'recursive attribute' => [
            static function (RouteCollector $r): void {
                $tree = [];
                $tree[] = &$tree;
                $r->get('/f', 'f')->attribute('tree', $tree);
            },
            "Route 'GET /f' cannot be exported: its attributes contains an array nested too deeply",
        ];
    }

    public function testAttributesNestedUpToTheLimitCanBeExported(): void
    {
        $tree = array_reduce(range(1, 62), static fn(array $tree): array => [$tree], []);
        $routes = new RouteCollector();
        $routes->get('/tree', 'tree')->attribute('tree', $tree);

        $restored = Router::fromArray($routes->build()->toArray());

        $this->assertSame($tree, $restored->getRoutes()[0]->attributes['tree']);
    }

    #[DataProvider('unsupportedData')]
    public function testFromArrayRejectsDataOfOtherVersions(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported router data');

        Router::fromArray($data);
    }

    public static function unsupportedData(): iterable
    {
        $valid = (new RouteCollector())->build()->toArray();

        yield 'empty' => [[]];
        yield 'other format' => [['format' => 0] + $valid];
        yield 'previous format' => [['format' => 1] + $valid];
        yield 'missing table' => [array_diff_key($valid, ['dynamic' => true])];
        yield 'table of another type' => [['routes' => 'none'] + $valid];
    }

    public function testLoadBuildsOnceAndThenUsesTheFile(): void
    {
        $file = $this->directory . '/routes.php';
        $builds = 0;
        $define = function (RouteCollector $routes) use (&$builds): void {
            $builds++;
            $this->define($routes);
        };

        $first = (new RouteCache($file))->load($define);
        $second = (new RouteCache($file))->load($define);

        $this->assertSame(1, $builds);
        $this->assertFileExists($file);
        $this->assertSameOutcomes($first, $second);
        $this->assertSameOutcomes($this->routes()->build(), $second);
    }

    #[DataProvider('unusableFiles')]
    public function testLoadReplacesAFileItCannotUse(string $contents): void
    {
        $file = $this->directory . '/routes.php';
        file_put_contents($file, $contents);
        $builds = 0;

        $router = (new RouteCache($file))->load(function (RouteCollector $routes) use (&$builds): void {
            $builds++;
            $this->define($routes);
        });

        $this->assertSame(1, $builds);
        $this->assertInstanceOf(Matched::class, $router->resolve('GET', '/users/42'));
        $this->assertInstanceOf(Router::class, (new RouteCache($file))->load($this->failOnBuild(...)));
    }

    public static function unusableFiles(): iterable
    {
        yield 'written by another version' => ["<?php return ['format' => 0, 'routes' => []];\n"];
        yield 'returning nothing' => ["<?php\n"];
    }

    public function testSaveCreatesDirectoriesAndLeavesNoTemporaryFiles(): void
    {
        $file = $this->directory . '/var/cache/routes.php';

        (new RouteCache($file))->save($this->routes()->build());

        $this->assertSame(['routes.php'], array_values(array_diff(scandir(dirname($file)), ['.', '..'])));
    }

    public function testSaveFailsWhenTheDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->directory . '/file', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create the route cache directory');

        (new RouteCache($this->directory . '/file/routes.php'))->save($this->routes()->build());
    }

    public function testSaveFailsWhenTheFileCannotBeReplacedAndLeavesNoTemporaryFile(): void
    {
        $file = $this->directory . '/routes.php';
        mkdir($file);

        try {
            (new RouteCache($file))->save($this->routes()->build());
            $this->fail('A directory must not be replaced by the cache file');
        } catch (RuntimeException $e) {
            $this->assertSame("Cannot write the route cache file $file", $e->getMessage());
        }
        $this->assertSame(['routes.php'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function testRoutesThatCannotBeCachedLeaveNoFile(): void
    {
        $file = $this->directory . '/routes.php';

        try {
            (new RouteCache($file))->load(static fn(RouteCollector $r) => $r->get('/a', static fn() => 'a'));
            $this->fail('A closure handler must not be cached');
        } catch (LogicException) {
        }

        $this->assertFileDoesNotExist($file);
    }

    public function testClearDeletesTheFile(): void
    {
        $file = $this->directory . '/routes.php';
        $cache = new RouteCache($file);
        $cache->save($this->routes()->build());

        $cache->clear();
        $cache->clear();

        $this->assertFileDoesNotExist($file);
    }

    public function testClearFailsWhenTheFileCannotBeDeleted(): void
    {
        stream_wrapper_register('undeletable', UndeletableStream::class);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot delete the route cache file undeletable://routes.php');
            (new RouteCache('undeletable://routes.php'))->clear();
        } finally {
            stream_wrapper_unregister('undeletable');
        }
    }

    private function routes(): RouteCollector
    {
        $routes = new RouteCollector();
        $this->define($routes);

        return $routes;
    }

    private function define(RouteCollector $routes): void
    {
        $routes->group('[/{lang:[a-z]{2}}]', function (RouteCollector $routes): void {
            $routes->get('[/]', ['App\Home', 'show'])->name('home');
            $routes->get('/users/{id}', ['App\Users', 'show'])->name('users.show')->attribute('permission', 'users');
            $routes->post('/users', ['App\Users', 'store']);
        }, ['locale']);
        $routes->get('/users/me', 'App\Profile')->middleware('auth');
        $routes->head('/users/{id:\d+}', 'App\UsersHead');
        $routes->get('/files/{path:.+}', 'App\Files');
        $routes->group('/admin', function (RouteCollector $routes): void {
            $routes->get('/reports', 'App\Reports');
            $routes->delete('/reports', 'App\Reports');
        }, ['auth', 'admin'], ['area' => 'admin']);
    }

    private function failOnBuild(): never
    {
        $this->fail('The cached router must be used');
    }

    private function assertSameOutcomes(Router $expected, Router $actual): void
    {
        foreach (self::URIS as $uri) {
            $this->assertSame($expected->getAllowedMethods($uri), $actual->getAllowedMethods($uri), $uri);
            foreach (['GET', 'HEAD', 'POST', 'DELETE', 'PATCH'] as $method) {
                $this->assertSame(
                    $this->outcome($expected->resolve($method, $uri)),
                    $this->outcome($actual->resolve($method, $uri)),
                    "$method $uri"
                );
            }
        }
    }

    /** @return list<mixed> */
    private function outcome(Matched|NotFound|MethodNotAllowed $result): array
    {
        return match (true) {
            $result instanceof Matched => [
                $result->route->method,
                $result->route->path,
                $result->route->handler,
                $result->route->middlewares,
                $result->route->name,
                $result->route->attributes,
                $result->params,
            ],
            $result instanceof MethodNotAllowed => ['not allowed', $result->allowedMethods],
            default => ['not found'],
        };
    }
}
