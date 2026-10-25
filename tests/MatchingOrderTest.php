<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Solo\Router\RouteCollector;
use Solo\Router\Router;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;
use Solo\Router\Results\NotFound;

final class MatchingOrderTest extends TestCase
{
    use ResolvesRoutes;

    #[DataProvider('overlappingRoutes')]
    public function testRegistrationOrderWinsAcrossDifferentPrefixes(array $patterns): void
    {
        $router = new RouteCollector();
        foreach ($patterns as $pattern) {
            $router->addRoute('GET', $pattern, $pattern);
        }

        $this->assertSame($patterns[0], $this->match($router, 'GET', '/api/users/42')['handler'] ?? null);
    }

    public static function overlappingRoutes(): iterable
    {
        $broad = '/{path:.*}';
        $api = '/api/{path:.*}';
        $users = '/api/users/{id}';

        yield [[$broad, $api, $users]];
        yield [[$broad, $users, $api]];
        yield [[$api, $broad, $users]];
        yield [[$api, $users, $broad]];
        yield [[$users, $broad, $api]];
        yield [[$users, $api, $broad]];
    }

    public function testSharedPatternsKeepRegistrationOrderForEachMethod(): void
    {
        $router = new RouteCollector();
        $router->addRoute('GET', '/api/users/{id}', 'get-specific');
        $router->addRoute('POST', '/api/{path:.*}', 'post-broad');
        $router->addRoute('GET', '/api/{path:.*}', 'get-broad');
        $router->addRoute('POST', '/api/users/{id}', 'post-specific');

        $this->assertSame('get-specific', $this->match($router, 'GET', '/api/users/42')['handler'] ?? null);
        $this->assertSame('post-broad', $this->match($router, 'POST', '/api/users/42')['handler'] ?? null);
        $this->assertSame(['GET', 'HEAD', 'POST'], $router->build()->getAllowedMethods('/api/users/42'));
    }

    #[DataProvider('prefixBoundaries')]
    public function testPrefixBoundariesPreserveMatching(string $pattern, string $uri, array $params): void
    {
        $router = new RouteCollector();
        $router->addRoute('GET', $pattern, 'expected');
        $router->addRoute('POST', $pattern, 'post');

        $match = $this->match($router, 'GET', $uri);
        $this->assertNotFalse($match);
        $this->assertSame('expected', $match['handler']);
        $this->assertSame($params, $match['params']);
        $this->assertSame(['GET', 'HEAD', 'POST'], $router->build()->getAllowedMethods($uri));
    }

    public static function prefixBoundaries(): iterable
    {
        yield 'partial segment' => ['/api/user-{id}', '/api/user-42', ['id' => '42']];
        yield 'optional segment absent' => ['/api/users[/{id}]', '/api/users', []];
        yield 'optional segment present' => ['/api/users[/{id}]', '/api/users/42', ['id' => '42']];
        yield 'empty parameter' => ['/api/users/{id:.*}', '/api/users/', []];
        yield 'optional prefix absent' => ['[/{lang}]/api/users/{id}', '/api/users/42', ['id' => '42']];
        yield 'optional prefix present' => [
            '[/{lang}]/api/users/{id}', '/en/api/users/42', ['lang' => 'en', 'id' => '42'],
        ];
        yield 'raw group' => ['/api/(users|posts)/{id}', '/api/posts/42', ['id' => '42']];
        yield 'relative path' => ['api/users/{id}', 'api/users/42', ['id' => '42']];
        yield 'repeated separators' => ['//api//users/{id}', '//api//users/42', ['id' => '42']];
        yield 'unicode literal' => ['/api/люди/{id}', '/api/люди/42', ['id' => '42']];
        yield 'encoded literal' => ['/api/a%2Fb/{id}', '/api/a%2Fb/42', ['id' => '42']];
        yield 'literal zero prefix' => ['0/{id}', '0/42', ['id' => '42']];
        yield 'empty path' => ['[/]', '', []];
        yield 'root path' => ['[/]', '/', []];
    }

    public function testDeeperPrefixesRemainReachableAfterAnEarlierCandidateFails(): void
    {
        $router = new RouteCollector();
        $router->addRoute('GET', '/api/{id:\d+}', 'numeric');
        $router->addRoute('GET', '/api/users/{id}', 'user');
        $router->addRoute('GET', '/api/users/{id}/posts/{post}', 'post');

        $this->assertSame('numeric', $this->match($router, 'GET', '/api/42')['handler'] ?? null);
        $this->assertSame('user', $this->match($router, 'GET', '/api/users/42')['handler'] ?? null);
        $this->assertSame('post', $this->match($router, 'GET', '/api/users/42/posts/7')['handler'] ?? null);
        $this->assertFalse($this->match($router, 'GET', '/api/users/42/posts/7/missing'));
        $this->assertSame([], $router->build()->getAllowedMethods('/api/users/42/posts/7/missing'));
    }

    public function testMethodsFromAllMatchingPatternsAreCombined(): void
    {
        $router = new RouteCollector();
        $router->addRoute('POST', '/{path:.*}', 'fallback');
        $router->addRoute('PUT', '/api/{path:.*}', 'api');
        $router->addRoute('GET', '/api/users/{id}', 'user');
        $router->addRoute('DELETE', '/api/users/{id}', 'delete');
        $router->addRoute('OPTIONS', '/api/users/42', 'options');

        $this->assertSame(
            ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            $router->build()->getAllowedMethods('/api/users/42')
        );
        $this->assertSame(['POST', 'PUT'], $router->build()->getAllowedMethods('/api/other'));
    }

    public function testHeadPrefersExplicitDynamicRouteOverGetFallback(): void
    {
        $router = new RouteCollector();
        $router->addRoute('GET', '/api/users/{id}', 'get');
        $router->addRoute('HEAD', '/api/{path:.*}', 'head');

        $this->assertSame('head', $this->match($router, 'HEAD', '/api/users/42')['handler'] ?? null);
        $this->assertFalse($this->match($router, 'head', '/api/users/42'));
    }

    public function testSubsequentBuildIncludesAdditionalRoutesAndMetadata(): void
    {
        $router = new RouteCollector();
        $router->addRoute('GET', '/api/posts/{id}', 'post');
        $this->assertFalse($this->match($router, 'GET', '/api/users/42'));

        $route = $router->addRoute('GET', '/api/users/{id}', 'user');
        $this->assertSame('user', $this->match($router, 'GET', '/api/users/42')['handler'] ?? null);

        $route->name('users.show')->middleware('auth')->attribute('permission', 'users.view');
        $match = $this->match($router, 'GET', '/api/users/42');
        $this->assertNotFalse($match);
        $this->assertSame('users.show', $match['name']);
        $this->assertSame(['auth'], $match['middlewares']);
        $this->assertSame(['permission' => 'users.view'], $match['attributes']);
    }

    #[DataProvider('segmentsAfterParameters')]
    public function testLiteralSegmentsAfterParametersPreservePathBoundaries(
        string $pattern,
        string $uri,
        string $miss,
        array $params
    ): void {
        $builder = new RouteCollector();
        $builder->get($pattern, 'expected');
        $builder->post($pattern, 'post');
        $router = $builder->build();

        $this->assertSame($params, $this->match($router, 'GET', $uri)['params'] ?? null);
        $this->assertSame(['GET', 'HEAD', 'POST'], $router->getAllowedMethods($uri));
        $this->assertFalse($this->match($router, 'GET', $miss));
        $this->assertSame([], $router->getAllowedMethods($miss));
    }

    public static function segmentsAfterParameters(): iterable
    {
        yield 'additional segment' => [
            '/api/{tenant}/users/{id}', '/api/acme/users/42', '/api/acme/users/42/extra',
            ['tenant' => 'acme', 'id' => '42'],
        ];
        yield 'different literal' => [
            '/{locale}/users/{id}', '/en/users/42', '/en/posts/42', ['locale' => 'en', 'id' => '42'],
        ];
        yield 'relative path' => [
            '{tenant}/users/{id}', 'acme/users/42', '/acme/users/42', ['tenant' => 'acme', 'id' => '42'],
        ];
        yield 'repeated separators' => [
            '//{tenant}//users/{id}', '//acme//users/42', '//acme/users/42', ['tenant' => 'acme', 'id' => '42'],
        ];
        yield 'unicode literal' => [
            '/{locale}/люди/{id}', '/uk/люди/42', '/uk/люд/42', ['locale' => 'uk', 'id' => '42'],
        ];
        yield 'numeric literal' => [
            '/{tenant}/0/{id}', '/a/0/42', '/a/00/42', ['tenant' => 'a', 'id' => '42'],
        ];
        yield 'encoded separator' => [
            '/{tenant}/a%2Fb/{id}', '/a/a%2Fb/42', '/a/a/b/42', ['tenant' => 'a', 'id' => '42'],
        ];
        yield 'trailing separator' => [
            '/{tenant}/users/{id}/', '/a/users/42/', '/a/users/42', ['tenant' => 'a', 'id' => '42'],
        ];
        yield 'consecutive parameters' => [
            '/{first}/{second}/users/{id}', '/a/b/users/42', '/a/b/posts/42',
            ['first' => 'a', 'second' => 'b', 'id' => '42'],
        ];
        yield 'later literals still checked' => [
            '/api/{tenant}/users/{id}/posts/{post}', '/api/a/users/42/posts/7', '/api/a/users/42/comments/7',
            ['tenant' => 'a', 'id' => '42', 'post' => '7'],
        ];
    }

    #[DataProvider('segmentAndCatchAllOrders')]
    public function testSegmentAndCatchAllPatternsKeepMethodSpecificOrder(array $patterns): void
    {
        $builder = new RouteCollector();
        foreach ($patterns as $pattern) {
            $builder->get($pattern, $pattern);
        }
        foreach (array_reverse($patterns) as $pattern) {
            $builder->post($pattern, $pattern);
        }
        $router = $builder->build();

        $this->assertSame($patterns[0], $this->match($router, 'GET', '/api/acme/users/42')['handler'] ?? null);
        $this->assertSame($patterns[1], $this->match($router, 'POST', '/api/acme/users/42')['handler'] ?? null);
    }

    public static function segmentAndCatchAllOrders(): iterable
    {
        yield 'catch-all first' => [['/{path:.*}', '/api/{tenant}/users/{id}']];
        yield 'segment first' => [['/api/{tenant}/users/{id}', '/{path:.*}']];
    }

    #[DataProvider('segmentAndCatchAllOrders')]
    public function testHeadAndAllowedMethodsCombineSegmentAndCatchAllPatterns(array $patterns): void
    {
        $builder = new RouteCollector();
        $builder->get($patterns[0], 'get');
        $builder->head($patterns[1], 'head');
        $builder->post($patterns[0], 'post');
        $builder->delete($patterns[1], 'delete');
        $router = $builder->build();

        $this->assertSame('head', $this->match($router, 'HEAD', '/api/acme/users/42')['handler'] ?? null);
        $result = $router->resolve('PATCH', '/api/acme/users/42');
        $this->assertInstanceOf(MethodNotAllowed::class, $result);
        $this->assertSame(['GET', 'HEAD', 'POST', 'DELETE'], $result->allowedMethods);
        $this->assertSame($result->allowedMethods, $router->getAllowedMethods('/api/acme/users/42'));
    }

    public function testBuildValidatesRegexAmongOtherRoutes(): void
    {
        $router = new RouteCollector();
        $router->addRoute('GET', '/api/broken/{id:a{2,1}}', 'invalid');
        $router->addRoute('GET', '/api/users/{id}', 'user');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /api/broken/{id:a{2,1}}' compiles to an invalid regular expression");
        $router->build();
    }

    public function testBuildValidatesRegexOfAnEnclosingPrefix(): void
    {
        $router = new RouteCollector();
        $router->addRoute('GET', '/api/{id:a{2,1}}', 'invalid');
        $router->addRoute('GET', '/api/users/{id}', 'user');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /api/{id:a{2,1}}' compiles to an invalid regular expression");
        $router->build();
    }

    public function testOverlappingSegmentPatternsPreservePerMethodOrderAndAllowedMethods(): void
    {
        $builder = new RouteCollector();
        $view = '/{tenant}/users/{id}/view';
        $generic = '/{tenant}/{resource}/42/{action}';
        $edit = '/{tenant}/users/{id}/edit';

        foreach ([$view, $generic, $edit] as $pattern) {
            $builder->get($pattern, $pattern);
        }
        foreach ([$edit, $generic, $view] as $pattern) {
            $builder->post($pattern, $pattern);
        }
        $builder->delete($view, $view);
        $router = $builder->build();

        foreach (['GET' => $generic, 'POST' => $edit, 'HEAD' => $generic] as $method => $expected) {
            $result = $router->resolve($method, '/acme/users/42/edit');
            $this->assertInstanceOf(Matched::class, $result);
            $this->assertSame($expected, $result->route->handler);
        }

        $result = $router->resolve('PATCH', '/acme/users/42/edit');
        $this->assertInstanceOf(MethodNotAllowed::class, $result);
        $this->assertSame(['GET', 'HEAD', 'POST'], $result->allowedMethods);
        $this->assertSame($result->allowedMethods, $router->getAllowedMethods('/acme/users/42/edit'));

        $result = $router->resolve('DELETE', '/acme/users/42/view');
        $this->assertInstanceOf(Matched::class, $result);
        $this->assertSame($view, $result->route->handler);
    }

    public function testRegexConstrainedFirstSegmentsKeepRegistrationOrder(): void
    {
        $builder = new RouteCollector();
        $builder->get('/{lang:en|ru}/users/{id:\d+}', 'numeric');
        $builder->get('/{lang:[a-z]{2}}/users/{id}', 'two-letter');
        $builder->get('/{tenant}/users/{id}', 'tenant');
        $builder->get('/{lang:[a-z]{2}}/posts/{id}', 'posts');
        $router = $builder->build();

        $match = $this->match($router, 'GET', '/en/users/42');
        $this->assertSame('numeric', $match['handler'] ?? null);
        $this->assertSame(['lang' => 'en', 'id' => '42'], $match['params'] ?? null);
        $this->assertSame('two-letter', $this->match($router, 'GET', '/en/users/me')['handler'] ?? null);
        $this->assertSame('two-letter', $this->match($router, 'GET', '/de/users/42')['handler'] ?? null);
        $this->assertSame('tenant', $this->match($router, 'GET', '/acme/users/42')['handler'] ?? null);
        $this->assertSame('posts', $this->match($router, 'GET', '/de/posts/7')['handler'] ?? null);
        $this->assertInstanceOf(NotFound::class, $router->resolve('GET', '/acme/posts/7'));
    }

    public function testOptionalPrefixRoutesKeepRegistrationOrder(): void
    {
        $builder = new RouteCollector();
        $builder->get('[/{lang:[a-z]{2}}]/users/{id}', 'localized');
        $builder->get('/{section}/users/{id}', 'section');
        $router = $builder->build();

        $this->assertSame(['id' => '42'], $this->match($router, 'GET', '/users/42')['params'] ?? null);
        $localized = $this->match($router, 'GET', '/en/users/42');
        $this->assertSame(['lang' => 'en', 'id' => '42'], $localized['params'] ?? null);
        $this->assertSame('section', $this->match($router, 'GET', '/admin/users/42')['handler'] ?? null);
    }

    /**
     * Sharing "/a[/x]" would try "present" for both routes before "absent" for the first one.
     */
    public function testOptionalSegmentsAreNotSharedBetweenRoutes(): void
    {
        $builder = new RouteCollector();
        $builder->get('/a[/x]/x/y', 'first');
        $builder->get('/a[/x]/y', 'second');
        $router = $builder->build();

        $this->assertSame('first', $this->match($router, 'GET', '/a/x/y')['handler'] ?? null);
        $this->assertSame('first', $this->match($router, 'GET', '/a/x/x/y')['handler'] ?? null);
        $this->assertSame('second', $this->match($router, 'GET', '/a/y')['handler'] ?? null);
    }

    /**
     * A parameter followed by literal text can backtrack, so routes must not share it.
     */
    public function testParameterBeforeLiteralTextIsNotSharedBetweenRoutes(): void
    {
        $builder = new RouteCollector();
        $builder->get('/{x}.b.c', 'first');
        $builder->get('/{x}.c', 'second');
        $router = $builder->build();

        $this->assertSame(['x' => 'a'], $this->match($router, 'GET', '/a.b.c')['params'] ?? null);
        $this->assertSame('first', $this->match($router, 'GET', '/a.b.c')['handler'] ?? null);
        $this->assertSame('second', $this->match($router, 'GET', '/a.c')['handler'] ?? null);
    }

    /**
     * A parameter that may match "/" can backtrack across segments, so routes must not share it.
     */
    public function testMultiSegmentParameterIsNotSharedBetweenRoutes(): void
    {
        $builder = new RouteCollector();
        $builder->get('/{x:.*}/a/b', 'first');
        $builder->get('/{x:.*}/b', 'second');
        $router = $builder->build();

        $this->assertSame(['x' => 'p'], $this->match($router, 'GET', '/p/a/b')['params'] ?? null);
        $this->assertSame('first', $this->match($router, 'GET', '/p/a/b')['handler'] ?? null);
        $this->assertSame('second', $this->match($router, 'GET', '/p/q/b')['handler'] ?? null);
    }

    public function testRawGroupThatCanSpanSegmentsIsNotSharedBetweenRoutes(): void
    {
        $builder = new RouteCollector();
        $builder->get('/(.*)/a/b', 'first');
        $builder->get('/(.*)/b', 'second');
        $router = $builder->build();

        $this->assertSame('first', $this->match($router, 'GET', '/p/a/b')['handler'] ?? null);
        $this->assertSame('second', $this->match($router, 'GET', '/p/q/b')['handler'] ?? null);
    }

    public function testRouteIsNotMovedAheadOfAnOverlappingEarlierRoute(): void
    {
        $builder = new RouteCollector();
        $builder->get('/a/{x}', 'show');
        $builder->get('/{any:.*}', 'catch-all');
        $builder->get('/a/{x}/edit', 'edit');
        $router = $builder->build();

        $this->assertSame('catch-all', $this->match($router, 'GET', '/a/1/edit')['handler'] ?? null);
        $this->assertSame('show', $this->match($router, 'GET', '/a/1')['handler'] ?? null);
    }

    public function testRouteIsNotMovedAheadOfAnEarlierRouteStartingWithAnotherGroup(): void
    {
        $builder = new RouteCollector();
        $builder->get('/{id}/a', 'a');
        $builder->get('/{any:.*}', 'catch-all');
        $builder->get('/{id}/b', 'b');
        $router = $builder->build();

        $this->assertSame('catch-all', $this->match($router, 'GET', '/5/b')['handler'] ?? null);
        $this->assertSame('a', $this->match($router, 'GET', '/5/a')['handler'] ?? null);
    }

    /**
     * White-box check of the prefix tree: lookup speed depends on routes sharing their prefixes,
     * and a regression there would still match correctly, only slower.
     */
    public function testRoutesShareLiteralAndSegmentPrefixesInOnePattern(): void
    {
        $builder = new RouteCollector();
        $builder->get('/users/{id}', 'show');
        $builder->get('/posts/{id}', 'post');
        $builder->get('/users/{id}/edit', 'edit');
        $builder->get('/{lang:[a-z]{2}}/about', 'about');
        $builder->get('/{lang:[a-z]{2}}/contact', 'contact');
        $builder->get('/(a|b)/x', 'raw-x');
        $builder->get('/(a|b)/y', 'raw-y');
        $builder->get('/files/{path:.+}', 'file');
        $builder->get('/files/{path:.+}/raw', 'raw');
        $builder->get('/news[/{page}]', 'news');
        $builder->get('/news[/{page}]/rss', 'rss');

        // Groups that can span segments or be absent are never shared
        $this->assertSame(
            ['#^(?|/(?|'
                . 'users/([^/]+)(?|$(*:0)|/edit$(*:2))'
                . '|posts/([^/]+)$(*:1)'
                . '|([a-z]{2})/(?|about$(*:3)|contact$(*:4))'
                . '|(?:(a|b))/(?|x$(*:5)|y$(*:6))'
                . '|files/(?|(.+)$(*:7)|(.+)/raw$(*:8))'
                . '|news(?|(?:/([^/]+))?$(*:9)|(?:/([^/]+))?/rss$(*:10))'
                . '))#D'],
            $builder->build()->toArray()['dynamic']['GET']
        );
    }

    public function testRouteMovesAheadOnlyOfRoutesWithDifferentLiterals(): void
    {
        $builder = new RouteCollector();
        $builder->get('/a/{x}', 'show-a');
        $builder->get('/b/{x}', 'show-b');
        $builder->get('/a/{x}/edit', 'edit-a');
        $builder->get('/b/{x}/edit', 'edit-b');
        $router = $builder->build();

        $expected = ['/a/1' => 'show-a', '/b/1' => 'show-b', '/a/1/edit' => 'edit-a', '/b/1/edit' => 'edit-b'];
        foreach ($expected as $uri => $handler) {
            $this->assertSame($handler, $this->match($router, 'GET', $uri)['handler'] ?? null, $uri);
        }
    }

    public function testLargeRouteTablesAreSplitIntoSeveralPatternsInOrder(): void
    {
        $builder = new RouteCollector();
        $builder->get('/{a}/{b}/early', 'early');
        for ($i = 0; $i < 3000; $i++) {
            $builder->get("/item$i/{id}/segment-with-a-long-unique-name-$i", "item$i");
        }
        $builder->get('/{path:.*}', 'catch-all');
        $router = $builder->build();

        // White-box check that this table really exceeds a single PCRE pattern
        $patterns = (new ReflectionProperty(Router::class, 'dynamic'))->getValue($router)['GET'];
        $this->assertGreaterThan(1, count($patterns));

        foreach ([0, 1499, 2999] as $i) {
            $match = $this->match($router, 'GET', "/item$i/7/segment-with-a-long-unique-name-$i");
            $this->assertSame("item$i", $match['handler'] ?? null);
            $this->assertSame(['id' => '7'], $match['params'] ?? null);
        }
        $this->assertSame('early', $this->match($router, 'GET', '/item5/7/early')['handler'] ?? null);
        $this->assertSame('catch-all', $this->match($router, 'GET', '/item5/7/other')['handler'] ?? null);
    }
}
