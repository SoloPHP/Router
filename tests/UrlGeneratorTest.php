<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Router\Enums\HttpMethod;
use Solo\Router\Results\Matched;
use Solo\Router\RouteCache;
use Solo\Router\RouteCollector;
use Solo\Router\Router;
use Solo\Router\UrlGenerator;

final class UrlGeneratorTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/solo-router-urls-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    #[DataProvider('paths')]
    public function testGeneratedPathResolvesToTheRouteWithTheValues(string $name, array $params, string $path): void
    {
        $values = array_map(strval(...), array_filter($params, static fn(mixed $value): bool => $value !== null));

        foreach ($this->routers() as $source => $router) {
            $this->assertSame($path, (new UrlGenerator($router))->generate($name, $params), $source);

            // resolve() keeps the bytes of the path, so it returns the values encoded
            $result = $router->resolve('GET', $path);
            $this->assertInstanceOf(Matched::class, $result, $source);
            $this->assertSame($name, $result->route->name, $source);
            $this->assertEquals($values, array_map(rawurldecode(...), $result->params), $source);
        }
    }

    public static function paths(): iterable
    {
        yield 'static route' => ['tasks.index', [], '/tasks'];
        yield 'parameter' => ['users.show', ['id' => 'alice'], '/users/alice'];
        yield 'integer value' => ['tasks.show', ['id' => 42], '/tasks/42'];
        yield 'group prefix' => ['admin.users.edit', ['id' => 7], '/admin/users/7/edit'];
        yield 'regex with a brace and a parenthesis' => ['tags.show', ['tag' => 'php'], '/tags/php'];
        yield 'whitespace around the name and the pattern' => ['spaced', ['id' => 3], '/spaced/3'];
        yield 'encoded value' => ['users.show', ['id' => 'a b?c#d%e'], '/users/a%20b%3Fc%23d%25e'];
        yield 'encoded UTF-8' => ['users.show', ['id' => 'café'], '/users/caf%C3%A9'];
        yield 'encoded slash' => ['users.show', ['id' => 'a/b'], '/users/a%2Fb'];
        yield 'slash that the pattern accepts' => ['files', ['path' => 'docs/read me.md'], '/files/docs/read%20me.md'];
        yield 'optional segment left out' => ['archive', [], '/archive'];
        yield 'null leaves an optional segment out' => ['archive', ['year' => null], '/archive'];
        yield 'optional segment' => ['archive', ['year' => 2026], '/archive/2026'];
        yield 'nested optional segment' => ['archive', ['year' => 2026, 'month' => '10'], '/archive/2026/10'];
        yield 'outer optional segment' => ['shop', ['cat' => 'books'], '/shop/category/books'];
        yield 'both optional segments' => [
            'shop',
            ['cat' => 'phones', 'subcat' => 'android'],
            '/shop/category/phones/subcategory/android',
        ];
        yield 'optional segments without parameters left out' => ['reports', [], '/reports'];
        yield 'optional suffix' => ['reports', ['format' => 'json'], '/reports.json'];
        yield 'home route' => ['home', [], '/'];
        yield 'optional prefix' => ['home', ['lang' => 'en'], '/en'];
        yield 'route in a group with an optional prefix' => ['docs', ['lang' => 'uk'], '/uk/docs'];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejected(string $name, array $params, string $message): void
    {
        $generator = new UrlGenerator($this->routes()->build());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $generator->generate($name, $params);
    }

    public static function invalidUrls(): iterable
    {
        yield 'unknown name' => ['tasks.edit', [], "Route with name 'tasks.edit' does not exist"];
        yield 'missing parameter' => [
            'tasks.show',
            [],
            "Cannot generate a URL for route 'tasks.show': parameter 'id' is missing",
        ];
        yield 'null for a required parameter' => ['tasks.show', ['id' => null], "parameter 'id' is missing"];
        yield 'first missing parameter' => ['compare', [], "parameter 'left' is missing"];
        yield 'value that does not match' => [
            'tasks.show',
            ['id' => 'abc'],
            "Cannot generate a URL for route 'tasks.show': parameter 'id' must match '\\d+', got 'abc'",
        ];
        // The whole value must match, not a part of it
        yield 'value that only starts with a match' => ['tasks.show', ['id' => '5a'], "got '5a'"];
        yield 'value that only ends with a match' => ['tasks.show', ['id' => 'a5'], "got 'a5'"];
        yield 'empty value' => ['users.show', ['id' => ''], "parameter 'id' must match '[^/]+', got ''"];
        yield 'value that matches only one alternative in part' => [
            'reports',
            ['format' => 'jsonp'],
            "parameter 'format' must match 'json|xml', got 'jsonp'",
        ];
        yield 'inner optional segment without the outer one' => [
            'shop',
            ['subcat' => 'android'],
            "parameter 'cat' is missing",
        ];
        yield 'unknown parameter' => [
            'tasks.show',
            ['id' => 5, 'page' => 2],
            "Cannot generate a URL for route 'tasks.show': it has no parameter 'page'; pass query values in \$query",
        ];
        yield 'parameter of a static route' => ['tasks.index', ['id' => 5], "it has no parameter 'id'"];
        yield 'raw regex group' => [
            'profile',
            [],
            "Cannot generate a URL for route 'profile': its path has a raw regex group, which no parameter can fill",
        ];
    }

    public function testQueryIsEncodedAsRfc3986Requires(): void
    {
        $generator = new UrlGenerator($this->routes()->build());

        $this->assertSame('/tasks', $generator->generate('tasks.index', [], []));
        $this->assertSame(
            '/tasks/5?tab=to%20do&filter%5Bstatus%5D=open',
            $generator->generate('tasks.show', ['id' => 5], [
                'tab' => 'to do',
                'filter' => ['status' => 'open'],
                'skipped' => null,
            ])
        );
    }

    private function routes(): RouteCollector
    {
        $routes = new RouteCollector();
        $routes->get('/tasks', 'tasks.index')->name('tasks.index');
        $routes->get('/tasks/{id:\d+}', 'tasks.show')->name('tasks.show');
        $routes->get('/users/{id}', 'users.show')->name('users.show');
        $routes->group('/admin', function (RouteCollector $routes): void {
            $routes->get('/users/{id:\d+}/edit', 'admin.users.edit')->name('admin.users.edit');
        });
        $routes->get('/tags/{tag:[^/)}]+}', 'tags.show')->name('tags.show');
        $routes->get('/spaced/{ id : \d+ }', 'spaced')->name('spaced');
        $routes->get('/compare/{left}/{right}', 'compare')->name('compare');
        // An enum makes the cache store the route serialized
        $routes->get('/files/{path:.+}', 'files')->name('files')->attribute('method', HttpMethod::GET);
        $routes->get('/archive[/{year:\d{4}}[/{month:\d{2}}]]', 'archive')->name('archive');
        $routes->get('/shop[/category/{cat}[/subcategory/{subcat}]]', 'shop')->name('shop');
        $routes->get('[/admin]/reports[.{format:json|xml}]', 'reports')->name('reports');
        $routes->group('[/{lang:[a-z]{2}}]', function (RouteCollector $routes): void {
            $routes->get('[/]', 'home')->name('home');
            $routes->get('/docs', 'docs')->name('docs');
        });
        $routes->get('/(admin|user)/profile', 'profile')->name('profile');

        return $routes;
    }

    /** @return array<string, Router> The same routes built, restored by fromArray() and loaded from a cache file */
    private function routers(): array
    {
        $built = $this->routes()->build();
        $cache = new RouteCache($this->file);
        $cache->save($built);

        return [
            'built' => $built,
            'restored' => Router::fromArray($built->toArray()),
            'cached' => $cache->load(fn() => $this->fail('The cached router must be used')),
        ];
    }
}
