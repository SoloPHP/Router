<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Router\RouteCollector;
use InvalidArgumentException;

class RouterTest extends TestCase
{
    use ResolvesRoutes;

    private RouteCollector $builder;

    protected function setUp(): void
    {
        $this->builder = new RouteCollector();
    }

    public function testAddRoute(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', function ($id) {
            return "User: $id";
        }, [
            'group' => '/api',
            'name' => 'users.show',
        ]);

        $routes = $this->builder->build()->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('GET', $routes[0]->method->value);
        $this->assertEquals('/api/users/{id}', $routes[0]->path);
        $this->assertSame('users.show', $routes[0]->name);
    }

    public function testAddRouteWithInvalidMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported HTTP method: INVALID');

        $this->builder->addRoute('INVALID', '/users', function () {
        });
    }

    public function testMatchRoute(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', function ($id) {
            return "User: $id";
        }, [
            'group' => '/api',
            'name' => 'users.show',
        ]);

        $match = $this->match($this->builder, 'GET', '/api/users/123');

        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '123'], $match['params']);
        $this->assertArrayHasKey('name', $match);
        $this->assertEquals('users.show', $match['name']);
    }

    public function testMatchRouteNotFound(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', function ($id) {
            return "User: $id";
        });

        $result = $this->match($this->builder, 'POST', '/api/users/123');
        $this->assertFalse($result);
    }

    public function testOptionalParametersAtTailShouldWork(): void
    {
        $this->builder->addRoute('GET', '/users/{id}/posts[/{page}]', function ($id, $page = null) {
            return "User $id posts: $page";
        });

        // Should match without optional parameter
        $result = $this->match($this->builder, 'GET', '/users/123/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123'], $result['params']);

        // Should match with optional parameter
        $result = $this->match($this->builder, 'GET', '/users/123/posts/2');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123', 'page' => '2'], $result['params']);
    }

    public function testMatchRouteWithLimitedLengthRegex(): void
    {
        $this->builder->addRoute('GET', '/{language:[a-z]{2}}/user', function ($language) {
            return "User page in language: $language";
        });

        // Should match valid 2-letter language codes
        $result = $this->match($this->builder, 'GET', '/en/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['language' => 'en'], $result['params']);

        $result = $this->match($this->builder, 'GET', '/ru/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['language' => 'ru'], $result['params']);

        // Should NOT match with 3 letters
        $result = $this->match($this->builder, 'GET', '/eng/user');
        $this->assertFalse($result);

        // Should NOT match with 1 letter
        $result = $this->match($this->builder, 'GET', '/e/user');
        $this->assertFalse($result);

        // Should NOT match with uppercase letters
        $result = $this->match($this->builder, 'GET', '/EN/user');
        $this->assertFalse($result);

        // Should NOT match with numbers
        $result = $this->match($this->builder, 'GET', '/12/user');
        $this->assertFalse($result);
    }

    public function testMatchRouteWithAlternationRegex(): void
    {
        $this->builder->addRoute('GET', '/{language:[a-z]{2}|[0-9]{2}}/user', function ($language) {
            return "User page in language: $language";
        });

        // Should match 2-letter language codes
        $result = $this->match($this->builder, 'GET', '/en/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['language' => 'en'], $result['params']);

        // Should match 2-digit numbers
        $result = $this->match($this->builder, 'GET', '/12/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['language' => '12'], $result['params']);

        // Should NOT match 3 letters
        $result = $this->match($this->builder, 'GET', '/abc/user');
        $this->assertFalse($result);

        // Should NOT match mix of letters and numbers
        $result = $this->match($this->builder, 'GET', '/a1/user');
        $this->assertFalse($result);
    }

    public function testMatchRouteWithOptionalLanguagePrefix(): void
    {
        $this->builder->addRoute('GET', '/{prefix:(?:[a-z]{2}\\/)?}user', function ($prefix = '') {
            $lang = rtrim($prefix, '/');
            return "User page, language: " . ($lang ?: 'default');
        });

        // Should match without language prefix
        $result = $this->match($this->builder, 'GET', '/user');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']); // Empty prefix is filtered out

        // Should match with language prefix
        $result = $this->match($this->builder, 'GET', '/ua/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['prefix' => 'ua/'], $result['params']);

        $result = $this->match($this->builder, 'GET', '/en/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['prefix' => 'en/'], $result['params']);

        // Should NOT match with invalid language code (3 letters)
        $result = $this->match($this->builder, 'GET', '/eng/user');
        $this->assertFalse($result);
    }

    public function testMultipleOptionalSegmentsInDifferentPositions(): void
    {
        $this->builder->addRoute(
            'GET',
            '/api[/v{version:[0-9]+}]/users[/{id}]/posts[/{page}]',
            function ($version = null, $id = null, $page = null) {
                return compact('version', 'id', 'page');
            }
        );

        // All parameters missing
        $result = $this->match($this->builder, 'GET', '/api/users/posts');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // Only version
        $result = $this->match($this->builder, 'GET', '/api/v2/users/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['version' => '2'], $result['params']);

        // Version and ID
        $result = $this->match($this->builder, 'GET', '/api/v2/users/123/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['version' => '2', 'id' => '123'], $result['params']);

        // All parameters
        $result = $this->match($this->builder, 'GET', '/api/v2/users/123/posts/5');
        $this->assertNotFalse($result);
        $this->assertEquals(['version' => '2', 'id' => '123', 'page' => '5'], $result['params']);

        // Without version, but with ID
        $result = $this->match($this->builder, 'GET', '/api/users/456/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '456'], $result['params']);
    }

    public function testNestedOptionalSegments(): void
    {
        $this->builder->addRoute(
            'GET',
            '/shop[/category/{cat}[/subcategory/{subcat}]]',
            function ($cat = null, $subcat = null) {
                return compact('cat', 'subcat');
            }
        );

        // Without parameters
        $result = $this->match($this->builder, 'GET', '/shop');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // Only category
        $result = $this->match($this->builder, 'GET', '/shop/category/electronics');
        $this->assertNotFalse($result);
        $this->assertEquals(['cat' => 'electronics'], $result['params']);

        // Category and subcategory
        $result = $this->match($this->builder, 'GET', '/shop/category/electronics/subcategory/phones');
        $this->assertNotFalse($result);
        $this->assertEquals(['cat' => 'electronics', 'subcat' => 'phones'], $result['params']);

        // Subcategory without category should NOT match
        $result = $this->match($this->builder, 'GET', '/shop/subcategory/phones');
        $this->assertFalse($result);
    }

    public function testOptionalSegmentWithRegexPattern(): void
    {
        $this->builder->addRoute(
            'GET',
            '/posts[/{year:[0-9]{4}}[/{month:[0-9]{2}}[/{day:[0-9]{2}}]]]',
            function ($year = null, $month = null, $day = null) {
                return compact('year', 'month', 'day');
            }
        );

        // Without date
        $result = $this->match($this->builder, 'GET', '/posts');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // Only year
        $result = $this->match($this->builder, 'GET', '/posts/2024');
        $this->assertNotFalse($result);
        $this->assertEquals(['year' => '2024'], $result['params']);

        // Year and month
        $result = $this->match($this->builder, 'GET', '/posts/2024/12');
        $this->assertNotFalse($result);
        $this->assertEquals(['year' => '2024', 'month' => '12'], $result['params']);

        // Full date
        $result = $this->match($this->builder, 'GET', '/posts/2024/12/25');
        $this->assertNotFalse($result);
        $this->assertEquals(['year' => '2024', 'month' => '12', 'day' => '25'], $result['params']);

        // Invalid year format
        $result = $this->match($this->builder, 'GET', '/posts/24');
        $this->assertFalse($result);
    }

    public function testOptionalPrefixAndSuffix(): void
    {
        $this->builder->addRoute(
            'GET',
            '[/admin]/users[.{format:json|xml}]',
            function ($format = 'html') {
                return "Format: $format";
            }
        );

        // Without prefix and suffix
        $result = $this->match($this->builder, 'GET', '/users');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // With prefix
        $result = $this->match($this->builder, 'GET', '/admin/users');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // With suffix
        $result = $this->match($this->builder, 'GET', '/users.json');
        $this->assertNotFalse($result);
        $this->assertEquals(['format' => 'json'], $result['params']);

        // With prefix and suffix
        $result = $this->match($this->builder, 'GET', '/admin/users.xml');
        $this->assertNotFalse($result);
        $this->assertEquals(['format' => 'xml'], $result['params']);

        // Invalid format
        $result = $this->match($this->builder, 'GET', '/users.pdf');
        $this->assertFalse($result);
    }

    public function testComplexOptionalSegmentsWithGroups(): void
    {
        $this->builder->addRoute(
            'GET',
            '/{lang:[a-z]{2}}[/admin]/users[/{id:[0-9]+}[/edit]]',
            function ($lang, $id = null) {
                $action = str_ends_with($_SERVER['REQUEST_URI'] ?? '', '/edit') ? 'edit' : 'view';
                return compact('lang', 'id', 'action');
            }
        );

        // Language is required
        $result = $this->match($this->builder, 'GET', '/users');
        $this->assertFalse($result);

        // Minimal path
        $result = $this->match($this->builder, 'GET', '/en/users');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en'], $result['params']);

        // With admin
        $result = $this->match($this->builder, 'GET', '/en/admin/users');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en'], $result['params']);

        // With ID
        $result = $this->match($this->builder, 'GET', '/en/admin/users/123');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en', 'id' => '123'], $result['params']);

        // With ID and edit
        $result = $this->match($this->builder, 'GET', '/en/admin/users/123/edit');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en', 'id' => '123'], $result['params']);

        // Without admin, but with ID
        $result = $this->match($this->builder, 'GET', '/en/users/456');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en', 'id' => '456'], $result['params']);
    }

    public function testInvalidBracketsThrowsOnBuild(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /users[/{id}' has an unmatched opening bracket");

        $this->builder->addRoute('GET', '/users[/{id}', fn() => '');
        $this->builder->build();
    }

    public function testUnmatchedClosingBracketThrowsOnBuild(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /users/{id}]' has an unmatched closing bracket at position 11");

        $this->builder->addRoute('GET', '/users/{id}]', fn() => '');
        $this->builder->build();
    }

    public function testUnmatchedClosingBracketWithoutParametersThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /users]' has an unmatched closing bracket at position 6");

        $this->builder->addRoute('GET', '/users]', fn() => '');
        $this->builder->build();
    }

    public function testInvalidBuildDoesNotBreakAnExistingSnapshot(): void
    {
        $this->builder->addRoute('GET', '/ok/{id}', 'ok');
        $router = $this->builder->build();
        $this->builder->addRoute('GET', '/broken[/{id}', 'broken');

        try {
            $this->builder->build();
            $this->fail('Invalid syntax must be rejected when building');
        } catch (InvalidArgumentException) {
        }

        $match = $this->match($router, 'GET', '/ok/1');
        $this->assertNotFalse($match);
        $this->assertSame(['id' => '1'], $match['params']);
        $this->assertCount(1, $router->getRoutes());
    }

    public function testBuildPreservesRouteHandlerAndAttributes(): void
    {
        $handler = fn() => 'test';
        $this->builder->addRoute('GET', '/test', $handler, [
            'attributes' => ['permission' => 'test.view'],
        ]);

        $router = $this->builder->build();
        $routes = $router->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame($handler, $routes[0]->handler);

        $match = $this->match($router, 'GET', '/test');
        $this->assertNotFalse($match);
        $this->assertSame(['permission' => 'test.view'], $match['attributes']);
    }

    public function testMatchReturnsNameForStaticRoute(): void
    {
        $this->builder->addRoute('GET', '/dashboard', fn() => 'dashboard', [
            'name' => 'dashboard',
        ]);

        $match = $this->match($this->builder, 'GET', '/dashboard');
        $this->assertNotFalse($match);
        $this->assertArrayHasKey('name', $match);
        $this->assertEquals('dashboard', $match['name']);
    }

    public function testMatchReturnsNullNameWhenNotSet(): void
    {
        $this->builder->addRoute('GET', '/no-name', fn() => 'test');

        $match = $this->match($this->builder, 'GET', '/no-name');
        $this->assertNotFalse($match);
        $this->assertArrayHasKey('name', $match);
        $this->assertNull($match['name']);
    }

    public function testMatchReturnsNameForDynamicRoute(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', fn($id) => "User $id", [
            'name' => 'users.show',
        ]);

        $match = $this->match($this->builder, 'GET', '/users/42');
        $this->assertNotFalse($match);
        $this->assertEquals('users.show', $match['name']);
    }

    public function testStaticRouteMatch(): void
    {
        $this->builder->addRoute('GET', '/static/path', fn() => 'static');
        $router = $this->builder->build();

        $match1 = $this->match($router, 'GET', '/static/path');
        $this->assertNotFalse($match1);

        $match2 = $this->match($router, 'GET', '/static/path');
        $this->assertNotFalse($match2);
        $this->assertEquals($match1, $match2);
    }

    public function testDynamicRouteWithoutParamsMatchesRepeatedly(): void
    {
        $this->builder->addRoute('GET', '/users[/]', fn() => 'users');
        $router = $this->builder->build();

        $match1 = $this->match($router, 'GET', '/users');
        $this->assertNotFalse($match1);

        $match2 = $this->match($router, 'GET', '/users');
        $this->assertEquals($match1, $match2);
    }

    public function testGetRouteByNameNotFound(): void
    {
        $this->builder->addRoute('GET', '/test', fn() => 'test');

        $route = $this->builder->build()->getRouteByName('nonexistent');
        $this->assertNull($route);
    }

    public function testNamesAreIndexedInEachBuild(): void
    {
        $this->builder->addRoute('GET', '/first', fn() => 'first')->name('first');

        $route1 = $this->builder->build()->getRouteByName('first');
        $this->assertNotNull($route1);

        $this->builder->addRoute('GET', '/second', fn() => 'second')->name('second');

        $route2 = $this->builder->build()->getRouteByName('second');
        $this->assertNotNull($route2);
    }

    public function testGetRouteByNameReturnsTheSnapshotInstance(): void
    {
        $this->builder->addRoute('GET', '/test', 'test')->name('test.route');
        $router = $this->builder->build();

        $route1 = $router->getRouteByName('test.route');
        $this->assertNotNull($route1);
        $this->assertSame($route1, $router->getRouteByName('test.route'));
        $this->assertSame($route1, $router->getRoutes()[0]);
        $this->assertSame($route1, $router->resolve('GET', '/test')->route);
    }

    public function testPatternWithCaptureGroup(): void
    {
        // Pattern with regular capture group () not (?:)
        $this->builder->addRoute('GET', '/{path:(foo|bar)/baz}', fn($path) => $path);

        $match = $this->match($this->builder, 'GET', '/foo/baz');
        $this->assertNotFalse($match);
        $this->assertEquals(['path' => 'foo/baz'], $match['params']);

        $match = $this->match($this->builder, 'GET', '/bar/baz');
        $this->assertNotFalse($match);
        $this->assertEquals(['path' => 'bar/baz'], $match['params']);
    }

    public function testHeadFallbackToGetForStaticRoute(): void
    {
        $this->builder->addRoute('GET', '/login', fn() => 'login page', [
            'name' => 'login',
            'attributes' => ['permission' => 'login.view'],
        ]);

        $match = $this->match($this->builder, 'HEAD', '/login');
        $this->assertNotFalse($match);
        $this->assertEquals('login', $match['name']);
        $this->assertEquals([], $match['params']);
        $this->assertSame(['permission' => 'login.view'], $match['attributes']);
    }

    public function testHeadFallbackToGetForDynamicRoute(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', fn($id) => "User $id", [
            'name' => 'users.show',
        ]);

        $match = $this->match($this->builder, 'HEAD', '/users/42');
        $this->assertNotFalse($match);
        $this->assertEquals('users.show', $match['name']);
        $this->assertEquals(['id' => '42'], $match['params']);
    }

    public function testHeadUsesExplicitHeadRouteOverGetFallback(): void
    {
        $this->builder->addRoute('GET', '/status', fn() => 'get status');
        $this->builder->addRoute('HEAD', '/status', fn() => 'head status', [
            'name' => 'status.head',
        ]);

        $match = $this->match($this->builder, 'HEAD', '/status');
        $this->assertNotFalse($match);
        $this->assertEquals('status.head', $match['name']);
    }

    public function testHeadDoesNotMatchWhenOnlyPostExists(): void
    {
        $this->builder->addRoute('POST', '/webhook', fn() => 'ok');

        $result = $this->match($this->builder, 'HEAD', '/webhook');
        $this->assertFalse($result);
    }

    public function testPatternWithTopLevelGroup(): void
    {
        // Pattern with top-level group (not inside parameter or ?:)
        $this->builder->addRoute('GET', '/(admin|user)/profile', fn() => 'profile');

        $match = $this->match($this->builder, 'GET', '/admin/profile');
        $this->assertNotFalse($match);

        $match = $this->match($this->builder, 'GET', '/user/profile');
        $this->assertNotFalse($match);

        // Should not match other paths
        $match = $this->match($this->builder, 'GET', '/guest/profile');
        $this->assertFalse($match);
    }

    public function testLiteralTextInsideOptionalSegmentIsEscaped(): void
    {
        $this->builder->addRoute('GET', '/users[.{format:json|xml}]', fn() => 'users');
        $this->builder->addRoute('GET', '/api[/v1.0]/ping', fn() => 'ping');
        $this->builder->addRoute('GET', '/lang/{id}[/c++]', fn() => 'cpp');

        $this->assertFalse($this->match($this->builder, 'GET', '/usersXjson'));
        $this->assertSame(['format' => 'json'], $this->match($this->builder, 'GET', '/users.json')['params'] ?? null);

        $this->assertFalse($this->match($this->builder, 'GET', '/api/v1-0/ping'));
        $this->assertNotFalse($this->match($this->builder, 'GET', '/api/v1.0/ping'));
        $this->assertNotFalse($this->match($this->builder, 'GET', '/api/ping'));

        $this->assertSame(['id' => '5'], $this->match($this->builder, 'GET', '/lang/5/c++')['params'] ?? null);
        $this->assertFalse($this->match($this->builder, 'GET', '/lang/5/ccc'));
    }

    public function testLiteralTextBeforeParameterIsEscaped(): void
    {
        $this->builder->addRoute('GET', '/a.b/{id}', fn() => 'dot');

        $this->assertNotFalse($this->match($this->builder, 'GET', '/a.b/1'));
        $this->assertFalse($this->match($this->builder, 'GET', '/axb/1'));
    }

    public function testRawGroupAtStartOfPath(): void
    {
        $this->builder->addRoute('GET', '(en|ru)/about', fn() => 'about');

        $this->assertNotFalse($this->match($this->builder, 'GET', 'en/about'));
        $this->assertNotFalse($this->match($this->builder, 'GET', 'ru/about'));
        $this->assertFalse($this->match($this->builder, 'GET', 'de/about'));
    }

    public function testRawGroupWithBraceQuantifier(): void
    {
        $this->builder->addRoute('GET', '/(ab){2}', fn() => 'abab');

        $this->assertNotFalse($this->match($this->builder, 'GET', '/abab'));
        $this->assertFalse($this->match($this->builder, 'GET', '/ab'));
    }

    public function testParameterPatternWithClosingDelimitersInCharacterClass(): void
    {
        $this->builder->addRoute('GET', '/tags/{tag:[^/)}]+}', fn() => 'tag');

        $this->assertSame(['tag' => 'php'], $this->match($this->builder, 'GET', '/tags/php')['params'] ?? null);
        $this->assertFalse($this->match($this->builder, 'GET', '/tags/a)b'));
    }

    public function testDuplicateParameterNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /{id}/{id}' has a duplicate parameter 'id'");

        $this->builder->addRoute('GET', '/{id}/{id}', fn() => '');
        $this->builder->build();
    }

    public function testInvalidParameterNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /a{2}' has an invalid parameter name '2'");

        $this->builder->addRoute('GET', '/a{2}', fn() => '');
        $this->builder->build();
    }

    public function testInvalidParameterRegexThrowsOnBuild(): void
    {
        $this->builder->addRoute('GET', '/{id:a{2,1}}', fn() => '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /{id:a{2,1}}' compiles to an invalid regular expression");

        $this->builder->build();
    }

    public function testWhitespaceAroundParameterNameAndPatternIsIgnored(): void
    {
        $this->builder->addRoute('GET', '/users/{ id : \d+ }', 'show');

        $this->assertSame(['id' => '42'], $this->match($this->builder, 'GET', '/users/42')['params'] ?? null);
        $this->assertFalse($this->match($this->builder, 'GET', '/users/me'));
    }

    public function testParameterNameWithInnerNewlineIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("has an invalid parameter name 'i\nd'");

        $this->builder->addRoute('GET', "/a/{i\nd:\d+}", fn() => '');
        $this->builder->build();
    }

    public function testEmptyParameterPatternThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /a/{id:}' has an empty pattern for parameter 'id'");

        $this->builder->addRoute('GET', '/a/{id:}', fn() => '');
        $this->builder->build();
    }

    public function testParametersAfterCapturingGroupsKeepTheirValues(): void
    {
        $this->builder->addRoute('GET', '/g/{type:(post|page)s?}/{id}', 'parameter');
        $this->builder->addRoute('GET', '/r/{lang}/(a|b)/{id}', 'raw');
        $router = $this->builder->build();

        $this->assertSame(['type' => 'pages', 'id' => '5'], $this->match($router, 'GET', '/g/pages/5')['params'] ?? null);
        $this->assertSame(['lang' => 'en', 'id' => '7'], $this->match($router, 'GET', '/r/en/b/7')['params'] ?? null);
    }

    public function testParameterLeftOpenAtTheEndOfThePathThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /users/{id' has an unmatched '{' at position 7");

        $this->builder->addRoute('GET', '/users/{id', 'open');
        $this->builder->build();
    }

    public function testHashInRegexIsAnOrdinaryCharacter(): void
    {
        $this->builder->addRoute('GET', '/tags/{tag:[^/#]+}', 'tag');
        $this->builder->addRoute('GET', '/raw/{x:a#b}', 'raw');
        $this->builder->addRoute('GET', '/escaped/{x:a\#b}', 'escaped');
        $router = $this->builder->build();

        $this->assertSame(['tag' => 'php'], $this->match($router, 'GET', '/tags/php')['params'] ?? null);
        $this->assertSame(['x' => 'a#b'], $this->match($router, 'GET', '/raw/a#b')['params'] ?? null);
        $this->assertSame(['x' => 'a#b'], $this->match($router, 'GET', '/escaped/a#b')['params'] ?? null);
    }

    #[DataProvider('unsupportedRegexSyntax')]
    public function testRegexThatCouldAffectOtherRoutesIsRejected(string $pattern, string $reason): void
    {
        $this->builder->addRoute('GET', $pattern, 'unsupported');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^Route \'GET .*\' uses unsupported regex syntax at position \d+: ' . preg_quote($reason, '/') . '$/s'
        );
        $this->builder->build();
    }

    public static function unsupportedRegexSyntax(): iterable
    {
        $verbs = 'backtracking verbs and other (*...) syntax are not allowed';
        $recursion = 'recursion and subroutine calls are not allowed';
        $named = 'named groups are not allowed; use a separate parameter';
        $unclosed = 'groups must open and close inside the parameter';
        $options = 'inline options must apply to a group, e.g. (?i:...), or they would change how later routes match';
        $backreference = 'absolute backreferences are not allowed; use \g{-1}';

        yield 'backtracking verb' => ['/a/{x:a(*COMMIT)b}', $verbs];
        yield 'mark verb' => ['/a/{x:a(*:7)}', $verbs];
        yield 'recursion' => ['/a/{x:a(?R)?}', $recursion];
        yield 'numbered subroutine' => ['/a/{x:(a)(?1)}', $recursion];
        yield 'relative subroutine' => ['/a/{x:(?+1)(a)}', $recursion];
        yield 'named subroutine' => ['/a/{x:(?&n)}', $recursion];
        yield 'oniguruma subroutine' => ['/a/{x:(a)\g<1>}', 'subroutine calls are not allowed'];
        yield 'quoted oniguruma subroutine' => ["/a/{x:(a)\\g'1'}", 'subroutine calls are not allowed'];
        yield 'named group' => ['/a/{x:(?P<y>\d+)}', $named];
        yield 'named group, short syntax' => ['/a/{x:(?<y>\d+)}', $named];
        yield 'quoted named group' => ["/a/{x:(?'y'\d+)}", $named];
        yield 'alternatives escaping the parameter' => ['/a/{x:a)|(b}', $unclosed];
        yield 'alternatives escaping through a quoted class' => ['/a/{x:[\Q](\E]a)|(b[\Q])\E]}', $unclosed];
        yield 'group left open at the end of the parameter' => ['/a/{x:(a}', $unclosed];
        yield 'verb after quoted text' => ['/a/{x:\Q[\E(*COMMIT)]}', $verbs];
        yield 'verb in a raw group' => ['/a/(b(*ACCEPT))', $verbs];
        yield 'inline options in a raw group' => ['/a/(?i)b', $options];
        yield 'inline option reset in a raw group' => ['/a/[(?^)b]', $options];
        yield 'extended mode' => ['/a/{x:(?x)a b}', "inline option 'x' is not allowed; only i, m, n, s and U are"];
        yield 'comment' => ['/a/{x:a(?#note)}', 'comments are not allowed'];
        yield 'callout' => ['/a/{x:a(?C1)}', 'callouts are not allowed'];
        yield 'conditional group' => ['/a/{x:(a)?(?(1)b|c)}', 'conditional groups are not allowed'];
        yield 'absolute backreference' => ['/a/{x:(a)\1}', $backreference];
        yield 'absolute backreference with \g' => ['/a/{x:(a)\g1}', $backreference];
        yield 'absolute backreference with \g{}' => ['/a/{x:(a)\g{1}}', 'this form of \g{...} is not allowed'];
        yield 'named reference' => ['/a/{x:(a)\k<a>}', 'named references are not allowed'];
        yield 'control character escape' => ['/a/{x:\cA}', 'control character escapes (\c) are not allowed'];
        yield 'Unicode name escape' => ['/a/{x:\N{U+41}}', '\N{...} is not allowed'];
        yield 'Unicode property without a name' => ['/a/{x:\p1}', 'a Unicode property needs a name'];
        yield 'POSIX collating element' => ['/a/{x:[[.a.]]}', 'POSIX collating elements are not supported'];
        yield 'POSIX equivalence class' => ['/a/{x:[[=a=]]}', 'POSIX collating elements are not supported'];
    }

    #[DataProvider('unterminatedRegexSyntax')]
    public function testUnterminatedRegexSyntaxIsRejected(string $path, string $message): void
    {
        $this->builder->addRoute('GET', $path, 'unterminated');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET $path' $message");
        $this->builder->build();
    }

    public static function unterminatedRegexSyntax(): iterable
    {
        yield 'character class' => ['/a/{x:[ab}', 'has an unterminated character class at position 6'];
        yield 'POSIX class at the end of the path' => ['/a/{x:[[:alpha}', 'has an unterminated character class at position 6'];
        yield 'quoted text' => ['/a/{x:\Qab}', 'has an unterminated \Q at position 6'];
        yield 'raw group' => ['/a/(b', "has an unmatched '(' at position 3"];
    }

    #[DataProvider('supportedRegexSyntax')]
    public function testSupportedRegexSyntaxIsReadAsPcreReadsIt(string $path, string $uri, array $params): void
    {
        $this->builder->addRoute('GET', $path, 'route');
        $this->builder->addRoute('GET', '/n/{id}', 'neighbour');
        $router = $this->builder->build();

        $match = $this->match($router, 'GET', $uri);
        $this->assertSame('route', $match['handler'] ?? null);
        $this->assertSame($params, $match['params'] ?? null);
        $this->assertSame('neighbour', $this->match($router, 'GET', '/n/5')['handler'] ?? null);
    }

    public static function supportedRegexSyntax(): iterable
    {
        yield 'quoted text' => ['/q/{x:\Q(*)\E}', '/q/(*)', ['x' => '(*)']];
        yield 'quoted "#"' => ['/q/{x:\Qa#b\E}', '/q/a#b', ['x' => 'a#b']];
        yield 'quoted "]" in a class' => ['/c/{x:[\Q]\E]+}', '/c/]]', ['x' => ']]']];
        yield 'leading "]" in a class' => ['/c/{x:[]a]+}', '/c/a]', ['x' => 'a]']];
        yield 'leading "]" in a negated class' => ['/c/{x:[^]/]+}', '/c/ab', ['x' => 'ab']];
        yield 'POSIX class' => ['/p/{x:[[:alpha:]]+}', '/p/abc', ['x' => 'abc']];
        yield 'negated POSIX class' => ['/p/{x:[[:^digit:]]+}', '/p/ab', ['x' => 'ab']];
        yield '"[:" that is not a POSIX class' => ['/p/{x:[[:a]+}', '/p/a:[', ['x' => 'a:[']];
        yield 'hexadecimal escape' => ['/h/{x:\x{41}+}', '/h/AA', ['x' => 'AA']];
        yield 'Unicode property' => ['/u/{x:\p{Lu}+}', '/u/AB', ['x' => 'AB']];
        yield 'relative backreference' => ['/b/{x:(?|(a)|(b))\g{-1}}', '/b/bb', ['x' => 'bb']];
        yield 'atomic group' => ['/a/{x:(?>a+)b}', '/a/aab', ['x' => 'aab']];
        yield 'scoped options in a raw group' => ['/i/(?i:about)', '/i/ABOUT', []];
        yield 'options inside a parameter' => ['/i/{x:(?i)abc}', '/i/ABC', ['x' => 'ABC']];
        yield 'escaped brace' => ['/e/{x:a\}b}', '/e/a}b', ['x' => 'a}b']];
        yield 'quantifier' => ['/r/{x:a{2}}', '/r/aa', ['x' => 'aa']];
        yield 'escaped space at the end' => ['/s/{x:a\ }', '/s/a ', ['x' => 'a ']];
        yield '"}" after a leading "]"' => ['/c/{x:[]}]+}', '/c/}]', ['x' => '}]']];
        yield '"}" after a negated leading "]"' => ['/c/{x:[^]}/]+}', '/c/ab', ['x' => 'ab']];
        yield '"}" after "\E" and a leading "]"' => ['/c/{x:[\E]}]+}', '/c/]}', ['x' => ']}']];
        yield '"}" after "\Q\E" and a leading "]"' => ['/c/{x:[\Q\E]}]+}', '/c/}]', ['x' => '}]']];
        yield '"^" after "\E"' => ['/c/{x:[\E^]}/]+}', '/c/ab', ['x' => 'ab']];
        yield '"}" after a POSIX class' => ['/p/{x:[[:alpha:]}]+}', '/p/a}b', ['x' => 'a}b']];
        yield 'group after \N' => ['/n/{x:\N(a)}', '/n/za', ['x' => 'za']];
        yield 'group after a one-letter Unicode property' => ['/u/{x:\pL(a)}', '/u/Ba', ['x' => 'Ba']];
        yield 'empty quoted text' => ['/q/{x:a\Q\E}', '/q/a', ['x' => 'a']];
        yield 'negative lookahead' => ['/l/{x:(?!admin)[a-z]+}', '/l/users', ['x' => 'users']];
        yield 'leading "]" after "\E"' => ['/c/{x:[\E]]+}', '/c/]]', ['x' => ']]']];
        yield 'leading "]" after "\Q\E"' => ['/c/{x:[\Q\E]]+}', '/c/]]', ['x' => ']]']];
        yield '"^" after the negating "^"' => ['/c/{x:[^^]}', '/c/b', ['x' => 'b']];
        yield 'octal escapes in a class' => ['/o/{x:[\1-\3]+}', "/o/\x02\x01", ['x' => "\x02\x01"]];
    }

    public function testLookaroundsAndLiteralParenthesesRemainSupported(): void
    {
        $this->builder->addRoute('GET', '/a/{x:(?<=/a/)\d+}', 'lookbehind');
        $this->builder->addRoute('GET', '/b/{x:[(*]+}', 'class');
        $this->builder->addRoute('GET', '/c/{x:\(\*\)}', 'escaped');
        $this->builder->addRoute('GET', '/d/{x:\Q(*)\E}', 'quoted');
        $router = $this->builder->build();

        $this->assertSame('lookbehind', $this->match($router, 'GET', '/a/42')['handler'] ?? null);
        $this->assertSame(['x' => '(*'], $this->match($router, 'GET', '/b/(*')['params'] ?? null);
        $this->assertSame(['x' => '(*)'], $this->match($router, 'GET', '/c/(*)')['params'] ?? null);
        $this->assertSame(['x' => '(*)'], $this->match($router, 'GET', '/d/(*)')['params'] ?? null);
    }

    public function testScopedInlineOptionsApplyOnlyToTheirRoute(): void
    {
        $this->builder->addRoute('GET', '/a/(?i:b)', 'raw');
        $this->builder->addRoute('GET', '/c/{x:(?i)d}', 'parameter');
        $this->builder->addRoute('GET', '/e/{id}', 'later');
        $router = $this->builder->build();

        $this->assertSame('raw', $this->match($router, 'GET', '/a/B')['handler'] ?? null);
        $this->assertSame(['x' => 'D'], $this->match($router, 'GET', '/c/D')['params'] ?? null);
        $this->assertFalse($this->match($router, 'GET', '/A/b'));
        $this->assertFalse($this->match($router, 'GET', '/E/1'));
    }

    #[DataProvider('equivalentPatterns')]
    public function testRouteThatCanNeverMatchIsRejected(string $first, string $second): void
    {
        $this->builder->addRoute('GET', $first, 'first');
        $this->builder->addRoute('POST', $second, 'other method');
        $this->builder->addRoute('GET', $second, 'unreachable');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET $second' can never match: 'GET $first' has the same pattern");
        $this->builder->build();
    }

    public static function equivalentPatterns(): iterable
    {
        yield 'parameter names' => ['/users/{id}', '/users/{uid}'];
        yield 'explicit default pattern' => ['/users/{id}', '/users/{id:[^/]+}'];
        yield 'whitespace' => ['/users/{id:\d+}', '/users/{ id : \d+ }'];
        yield 'optional segment' => ['/posts[/{page}]', '/posts[/{p}]'];
    }

    #[DataProvider('pathsWithNul')]
    public function testNulByteInPathIsRejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET $path' contains a NUL byte");

        $this->builder->addRoute('GET', $path, 'nul');
        $this->builder->build();
    }

    public static function pathsWithNul(): iterable
    {
        yield 'static' => ["/a\0b"];
        yield 'dynamic' => ["/a\0b/{id}"];
    }

    public function testDuplicateStaticRouteThrows(): void
    {
        $this->builder->addRoute('GET', '/a', fn() => 'first');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /a' is already registered");

        $this->builder->addRoute('GET', '/a', fn() => 'second');
        $this->builder->build();
    }

    public function testDuplicateDynamicRouteThrows(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', fn() => 'first', ['group' => '/api']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /api/users/{id}' is already registered");

        $this->builder->addRoute('GET', '/api/users/{id}', fn() => 'second');
        $this->builder->build();
    }

    public function testSamePathWithDifferentMethodsIsAllowed(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', fn() => 'show');
        $this->builder->addRoute('PUT', '/users/{id}', fn() => 'update');

        $this->assertCount(2, $this->builder->build()->getRoutes());
    }

    public function testStaticRouteTakesPrecedenceOverEarlierDynamicRoute(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', 'show');
        $this->builder->addRoute('GET', '/users/me', 'me');

        $this->assertSame('me', $this->match($this->builder, 'GET', '/users/me')['handler'] ?? null);
        $this->assertSame('show', $this->match($this->builder, 'GET', '/users/1')['handler'] ?? null);
    }

    public function testDuplicateNameThrowsOnBuild(): void
    {
        $this->builder->addRoute('GET', '/a', fn() => 'a', ['name' => 'same']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route with name 'same' already exists");

        $this->builder->addRoute('GET', '/b', fn() => 'b', ['name' => 'same']);
        $this->builder->build();
    }

    public function testRejectedBuildDoesNotAddNamesToAnExistingSnapshot(): void
    {
        $this->builder->addRoute('GET', '/a', 'a');
        $router = $this->builder->build();
        $this->builder->addRoute('GET', '/a', 'duplicate', ['name' => 'dup']);

        try {
            $this->builder->build();
            $this->fail('Duplicate routes must be rejected when building');
        } catch (InvalidArgumentException) {
        }

        $this->assertNull($router->getRouteByName('dup'));
        $this->assertSame('a', $this->match($router, 'GET', '/a')['handler']);
    }

    public function testNewNameAppearsOnlyInANewSnapshot(): void
    {
        $definition = $this->builder->addRoute('GET', '/x', 'x');
        $router = $this->builder->build();
        $definition->name('x.show');

        $this->assertNull($router->getRouteByName('x.show'));
        $this->assertSame('x.show', $this->builder->build()->getRouteByName('x.show')->name);
    }

    public function testRenamingARouteChangesOnlyTheNextSnapshot(): void
    {
        $definition = $this->builder->addRoute('GET', '/x', 'x')->name('old');
        $router = $this->builder->build();
        $definition->name('new');
        $rebuilt = $this->builder->build();

        $this->assertSame('old', $router->getRouteByName('old')->name);
        $this->assertNull($router->getRouteByName('new'));
        $this->assertNull($rebuilt->getRouteByName('old'));
        $this->assertSame('new', $rebuilt->getRouteByName('new')->name);
        $this->assertSame('new', $this->match($rebuilt, 'GET', '/x')['name']);
    }

    public function testDuplicateNameRejectsBuildAndCanBeCorrected(): void
    {
        $this->builder->addRoute('GET', '/a', 'a')->name('a');
        $definition = $this->builder->addRoute('GET', '/b', 'b')->name('b');
        $router = $this->builder->build();
        $definition->name('a');

        try {
            $this->builder->build();
            $this->fail('Duplicate names must be rejected when building');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame('/b', $router->getRouteByName('b')->path);
        $definition->name('corrected');
        $rebuilt = $this->builder->build();
        $this->assertSame('/b', $rebuilt->getRouteByName('corrected')->path);
        $this->assertNull($rebuilt->getRouteByName('b'));
    }

    public function testMetadataChangesAreVisibleOnlyInTheNextSnapshot(): void
    {
        $definition = $this->builder->addRoute('GET', '/p[/]', 'p');
        $router = $this->builder->build();
        $definition->middleware('auth')->attribute('permission', 'p.view');

        $before = $this->match($router, 'GET', '/p');
        $after = $this->match($this->builder->build(), 'GET', '/p');
        $this->assertSame([], $before['middlewares']);
        $this->assertSame([], $before['attributes']);
        $this->assertSame(['auth'], $after['middlewares']);
        $this->assertSame(['permission' => 'p.view'], $after['attributes']);
    }

    public function testOptionsRoute(): void
    {
        $this->builder->addRoute('OPTIONS', '/{any:.*}', fn() => 'preflight');

        $match = $this->match($this->builder, 'OPTIONS', '/api/users');
        $this->assertNotFalse($match);
        $this->assertSame(['any' => 'api/users'], $match['params']);
    }

    public function testGetAllowedMethods(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', fn() => 'show');
        $this->builder->addRoute('PUT', '/users/{id}', fn() => 'update');
        $this->builder->addRoute('POST', '/users', fn() => 'store');

        $this->assertSame(['GET', 'HEAD', 'PUT'], $this->builder->build()->getAllowedMethods('/users/1'));
        $this->assertSame(['POST'], $this->builder->build()->getAllowedMethods('/users'));
        $this->assertSame([], $this->builder->build()->getAllowedMethods('/missing'));
    }

    public function testGetAllowedMethodsCombinesStaticDynamicAndExplicitHead(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', fn() => 'show');
        $this->builder->addRoute('DELETE', '/users/{id}', fn() => 'delete');
        $this->builder->addRoute('PATCH', '/users/{id:\d+}', fn() => 'patch');
        $this->builder->addRoute('OPTIONS', '/users/me', fn() => 'options');
        $this->builder->addRoute('HEAD', '/ping', fn() => 'ping');

        $this->assertSame(['GET', 'HEAD', 'PATCH', 'DELETE'], $this->builder->build()->getAllowedMethods('/users/1'));
        $this->assertSame(['GET', 'HEAD', 'DELETE', 'OPTIONS'], $this->builder->build()->getAllowedMethods('/users/me'));
        $this->assertSame(['HEAD'], $this->builder->build()->getAllowedMethods('/ping'));
    }

    public function testGetAllowedMethodsReportsInvalidParameterRegex(): void
    {
        $this->builder->addRoute('GET', '/{id:a{2,1}}', fn() => '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /{id:a{2,1}}' compiles to an invalid regular expression");

        $this->builder->build();
    }

    public function testMethodIsMatchedCaseSensitively(): void
    {
        $this->builder->addRoute('post', '/users', fn() => 'store');
        $this->builder->addRoute('GET', '/status', fn() => 'status');

        $this->assertNotFalse($this->match($this->builder, 'POST', '/users'), 'Registration stays case-insensitive');
        $this->assertFalse($this->match($this->builder, 'post', '/users'));
        $this->assertFalse($this->match($this->builder, 'head', '/status'), 'Only HEAD falls back to GET');
        $this->assertSame(['POST'], $this->builder->build()->getAllowedMethods('/users'));
    }

    public function testTrailingNewlineDoesNotMatchEndOfPath(): void
    {
        $this->builder->addRoute('GET', '/users/{id:\d+}', fn() => 'show');
        $this->builder->addRoute('GET', '/files/{name}/download', fn() => 'download');

        $this->assertFalse($this->match($this->builder, 'GET', "/users/1\n"));
        $this->assertFalse($this->match($this->builder, 'GET', "/files/a/download\n"));
        $this->assertSame([], $this->builder->build()->getAllowedMethods("/users/1\n"));
    }

    public function testParamsAreReturnedWithoutDecoding(): void
    {
        $this->builder->addRoute('GET', '/files/{name}', fn() => 'file');

        $this->assertSame(['name' => 'a%2Fb'], $this->match($this->builder, 'GET', '/files/a%2Fb')['params'] ?? null);
    }

    public function testAddRouteWithAttributes(): void
    {
        $route = $this->builder->addRoute('GET', '/reports', fn() => 'reports', [
            'attributes' => ['permission' => 'reports.view'],
        ]);

        $this->assertSame(['permission' => 'reports.view'], $route->getAttributes());

        $match = $this->match($this->builder, 'GET', '/reports');
        $this->assertNotFalse($match);
        $this->assertSame(['permission' => 'reports.view'], $match['attributes']);
    }

    public function testMatchReturnsEmptyAttributesWhenNotSet(): void
    {
        $this->builder->addRoute('GET', '/users/{id}', fn() => 'show');

        $match = $this->match($this->builder, 'GET', '/users/1');
        $this->assertNotFalse($match);
        $this->assertSame([], $match['attributes']);
    }

    public function testAttributeReplacesEarlierValue(): void
    {
        $route = $this->builder->addRoute('GET', '/reports', fn() => 'reports', [
            'attributes' => ['permission' => 'reports.view', 'plan' => 'basic'],
        ]);

        $route->attribute('plan', 'pro')->attribute('plan', 'enterprise');

        $this->assertSame(['permission' => 'reports.view', 'plan' => 'enterprise'], $route->getAttributes());
    }

    public function testGetAttributeReturnsDefaultOnlyForMissingKey(): void
    {
        $route = $this->builder->addRoute('GET', '/p', fn() => 'p')->attribute('cache', null);

        $this->assertNull($route->getAttribute('cache', 60), 'A stored null must not fall back to the default');
        $this->assertSame(60, $route->getAttribute('rate_limit', 60));
        $this->assertNull($route->getAttribute('rate_limit'));
    }

    public function testIntegerAttributeKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route attribute key '0' must be a name, not an integer");

        $this->builder->addRoute('GET', '/admin', fn() => 'admin', ['attributes' => ['AuthMiddleware']]);
    }

    public function testIntegerStringAttributeKeyThrows(): void
    {
        $route = $this->builder->addRoute('GET', '/p', fn() => 'p');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route attribute key '42' must be a name, not an integer");

        $route->attribute('42', 'x');
    }

    public function testNonCanonicalNumericAttributeKeysStayStrings(): void
    {
        $route = $this->builder->addRoute('GET', '/p', fn() => 'p')
            ->attribute('007', 'agent')
            ->attribute('1.5', 'ratio');

        $this->assertSame(['007' => 'agent', '1.5' => 'ratio'], $route->getAttributes());
    }
}
