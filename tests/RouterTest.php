<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use PHPUnit\Framework\TestCase;
use Solo\Router\Router;
use InvalidArgumentException;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testAddRoute(): void
    {
        $this->router->addRoute('GET', '/users/{id}', function ($id) {
            return "User: $id";
        }, [
            'group' => '/api',
            'name' => 'users.show',
        ]);

        $routes = $this->router->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('GET', $routes[0]->method->value);
        $this->assertEquals('/api', $routes[0]->group);
        $this->assertEquals('/users/{id}', $routes[0]->path);
    }

    public function testAddRouteWithInvalidMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported HTTP method: INVALID');

        $this->router->addRoute('INVALID', '/users', function () {
        });
    }

    public function testMatchRoute(): void
    {
        $this->router->addRoute('GET', '/users/{id}', function ($id) {
            return "User: $id";
        }, [
            'group' => '/api',
            'name' => 'users.show',
        ]);

        $match = $this->router->match('GET', '/api/users/123');

        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '123'], $match['params']);
        $this->assertArrayHasKey('name', $match);
        $this->assertEquals('users.show', $match['name']);
    }

    public function testMatchRouteNotFound(): void
    {
        $this->router->addRoute('GET', '/users/{id}', function ($id) {
            return "User: $id";
        });

        $result = $this->router->match('POST', '/api/users/123');
        $this->assertFalse($result);
    }

    public function testOptionalParametersAtTailShouldWork(): void
    {
        $this->router->addRoute('GET', '/users/{id}/posts[/{page}]', function ($id, $page = null) {
            return "User $id posts: $page";
        });

        // Should match without optional parameter
        $result = $this->router->match('GET', '/users/123/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123'], $result['params']);

        // Should match with optional parameter
        $result = $this->router->match('GET', '/users/123/posts/2');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123', 'page' => '2'], $result['params']);
    }

    public function testMatchRouteWithLimitedLengthRegex(): void
    {
        $this->router->addRoute('GET', '/{language:[a-z]{2}}/user', function ($language) {
            return "User page in language: $language";
        });

        // Should match valid 2-letter language codes
        $result = $this->router->match('GET', '/en/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['language' => 'en'], $result['params']);

        $result = $this->router->match('GET', '/ru/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['language' => 'ru'], $result['params']);

        // Should NOT match with 3 letters
        $result = $this->router->match('GET', '/eng/user');
        $this->assertFalse($result);

        // Should NOT match with 1 letter
        $result = $this->router->match('GET', '/e/user');
        $this->assertFalse($result);

        // Should NOT match with uppercase letters
        $result = $this->router->match('GET', '/EN/user');
        $this->assertFalse($result);

        // Should NOT match with numbers
        $result = $this->router->match('GET', '/12/user');
        $this->assertFalse($result);
    }

    public function testMatchRouteWithAlternationRegex(): void
    {
        $this->router->addRoute('GET', '/{language:[a-z]{2}|[0-9]{2}}/user', function ($language) {
            return "User page in language: $language";
        });

        // Should match 2-letter language codes
        $result = $this->router->match('GET', '/en/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['language' => 'en'], $result['params']);

        // Should match 2-digit numbers
        $result = $this->router->match('GET', '/12/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['language' => '12'], $result['params']);

        // Should NOT match 3 letters
        $result = $this->router->match('GET', '/abc/user');
        $this->assertFalse($result);

        // Should NOT match mix of letters and numbers
        $result = $this->router->match('GET', '/a1/user');
        $this->assertFalse($result);
    }

    public function testMatchRouteWithOptionalLanguagePrefix(): void
    {
        $this->router->addRoute('GET', '/{prefix:(?:[a-z]{2}\\/)?}user', function ($prefix = '') {
            $lang = rtrim($prefix, '/');
            return "User page, language: " . ($lang ?: 'default');
        });

        // Should match without language prefix
        $result = $this->router->match('GET', '/user');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']); // Empty prefix is filtered out

        // Should match with language prefix
        $result = $this->router->match('GET', '/ua/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['prefix' => 'ua/'], $result['params']);

        $result = $this->router->match('GET', '/en/user');
        $this->assertNotFalse($result);
        $this->assertEquals(['prefix' => 'en/'], $result['params']);

        // Should NOT match with invalid language code (3 letters)
        $result = $this->router->match('GET', '/eng/user');
        $this->assertFalse($result);
    }

    public function testMultipleOptionalSegmentsInDifferentPositions(): void
    {
        $this->router->addRoute(
            'GET',
            '/api[/v{version:[0-9]+}]/users[/{id}]/posts[/{page}]',
            function ($version = null, $id = null, $page = null) {
                return compact('version', 'id', 'page');
            }
        );

        // All parameters missing
        $result = $this->router->match('GET', '/api/users/posts');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // Only version
        $result = $this->router->match('GET', '/api/v2/users/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['version' => '2'], $result['params']);

        // Version and ID
        $result = $this->router->match('GET', '/api/v2/users/123/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['version' => '2', 'id' => '123'], $result['params']);

        // All parameters
        $result = $this->router->match('GET', '/api/v2/users/123/posts/5');
        $this->assertNotFalse($result);
        $this->assertEquals(['version' => '2', 'id' => '123', 'page' => '5'], $result['params']);

        // Without version, but with ID
        $result = $this->router->match('GET', '/api/users/456/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '456'], $result['params']);
    }

    public function testNestedOptionalSegments(): void
    {
        $this->router->addRoute(
            'GET',
            '/shop[/category/{cat}[/subcategory/{subcat}]]',
            function ($cat = null, $subcat = null) {
                return compact('cat', 'subcat');
            }
        );

        // Without parameters
        $result = $this->router->match('GET', '/shop');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // Only category
        $result = $this->router->match('GET', '/shop/category/electronics');
        $this->assertNotFalse($result);
        $this->assertEquals(['cat' => 'electronics'], $result['params']);

        // Category and subcategory
        $result = $this->router->match('GET', '/shop/category/electronics/subcategory/phones');
        $this->assertNotFalse($result);
        $this->assertEquals(['cat' => 'electronics', 'subcat' => 'phones'], $result['params']);

        // Subcategory without category should NOT match
        $result = $this->router->match('GET', '/shop/subcategory/phones');
        $this->assertFalse($result);
    }

    public function testOptionalSegmentWithRegexPattern(): void
    {
        $this->router->addRoute(
            'GET',
            '/posts[/{year:[0-9]{4}}[/{month:[0-9]{2}}[/{day:[0-9]{2}}]]]',
            function ($year = null, $month = null, $day = null) {
                return compact('year', 'month', 'day');
            }
        );

        // Without date
        $result = $this->router->match('GET', '/posts');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // Only year
        $result = $this->router->match('GET', '/posts/2024');
        $this->assertNotFalse($result);
        $this->assertEquals(['year' => '2024'], $result['params']);

        // Year and month
        $result = $this->router->match('GET', '/posts/2024/12');
        $this->assertNotFalse($result);
        $this->assertEquals(['year' => '2024', 'month' => '12'], $result['params']);

        // Full date
        $result = $this->router->match('GET', '/posts/2024/12/25');
        $this->assertNotFalse($result);
        $this->assertEquals(['year' => '2024', 'month' => '12', 'day' => '25'], $result['params']);

        // Invalid year format
        $result = $this->router->match('GET', '/posts/24');
        $this->assertFalse($result);
    }

    public function testOptionalPrefixAndSuffix(): void
    {
        $this->router->addRoute(
            'GET',
            '[/admin]/users[.{format:json|xml}]',
            function ($format = 'html') {
                return "Format: $format";
            }
        );

        // Without prefix and suffix
        $result = $this->router->match('GET', '/users');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // With prefix
        $result = $this->router->match('GET', '/admin/users');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // With suffix
        $result = $this->router->match('GET', '/users.json');
        $this->assertNotFalse($result);
        $this->assertEquals(['format' => 'json'], $result['params']);

        // With prefix and suffix
        $result = $this->router->match('GET', '/admin/users.xml');
        $this->assertNotFalse($result);
        $this->assertEquals(['format' => 'xml'], $result['params']);

        // Invalid format
        $result = $this->router->match('GET', '/users.pdf');
        $this->assertFalse($result);
    }

    public function testComplexOptionalSegmentsWithGroups(): void
    {
        $this->router->addRoute(
            'GET',
            '/{lang:[a-z]{2}}[/admin]/users[/{id:[0-9]+}[/edit]]',
            function ($lang, $id = null) {
                $action = str_ends_with($_SERVER['REQUEST_URI'] ?? '', '/edit') ? 'edit' : 'view';
                return compact('lang', 'id', 'action');
            }
        );

        // Language is required
        $result = $this->router->match('GET', '/users');
        $this->assertFalse($result);

        // Minimal path
        $result = $this->router->match('GET', '/en/users');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en'], $result['params']);

        // With admin
        $result = $this->router->match('GET', '/en/admin/users');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en'], $result['params']);

        // With ID
        $result = $this->router->match('GET', '/en/admin/users/123');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en', 'id' => '123'], $result['params']);

        // With ID and edit
        $result = $this->router->match('GET', '/en/admin/users/123/edit');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en', 'id' => '123'], $result['params']);

        // Without admin, but with ID
        $result = $this->router->match('GET', '/en/users/456');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en', 'id' => '456'], $result['params']);
    }

    public function testInvalidBracketsThrowsOnRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unmatched opening bracket(s) in: /users[/{id}');

        $this->router->addRoute('GET', '/users[/{id}', fn() => '');
    }

    public function testUnmatchedClosingBracketThrowsOnRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unmatched closing bracket');

        $this->router->addRoute('GET', '/users/{id}]', fn() => '');
    }

    public function testUnmatchedClosingBracketWithoutParametersThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unmatched closing bracket');

        $this->router->addRoute('GET', '/users]', fn() => '');
    }

    public function testInvalidRouteDoesNotBreakOtherRoutes(): void
    {
        try {
            $this->router->addRoute('GET', '/broken[/{id}', fn() => 'broken');
        } catch (InvalidArgumentException) {
        }
        $this->router->addRoute('GET', '/ok/{id}', fn() => 'ok');

        $match = $this->router->match('GET', '/ok/1');
        $this->assertNotFalse($match);
        $this->assertSame(['id' => '1'], $match['params']);
        $this->assertCount(1, $this->router->getRoutes());
    }

    public function testConstructorWithRoutes(): void
    {
        $route = new \Solo\Router\Route(
            method: \Solo\Router\Enums\HttpMethod::GET,
            group: '',
            path: '/test',
            handler: fn() => 'test',
            attributes: ['permission' => 'test.view']
        );

        $router = new Router([$route]);

        $routes = $router->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertSame($route, $routes[0]);

        $match = $router->match('GET', '/test');
        $this->assertNotFalse($match);
        $this->assertSame(['permission' => 'test.view'], $match['attributes']);
    }

    public function testMatchReturnsNameForStaticRoute(): void
    {
        $this->router->addRoute('GET', '/dashboard', fn() => 'dashboard', [
            'name' => 'dashboard',
        ]);

        $match = $this->router->match('GET', '/dashboard');
        $this->assertNotFalse($match);
        $this->assertArrayHasKey('name', $match);
        $this->assertEquals('dashboard', $match['name']);
    }

    public function testMatchReturnsNullNameWhenNotSet(): void
    {
        $this->router->addRoute('GET', '/no-name', fn() => 'test');

        $match = $this->router->match('GET', '/no-name');
        $this->assertNotFalse($match);
        $this->assertArrayHasKey('name', $match);
        $this->assertNull($match['name']);
    }

    public function testMatchReturnsNameForDynamicRoute(): void
    {
        $this->router->addRoute('GET', '/users/{id}', fn($id) => "User $id", [
            'name' => 'users.show',
        ]);

        $match = $this->router->match('GET', '/users/42');
        $this->assertNotFalse($match);
        $this->assertEquals('users.show', $match['name']);
    }

    public function testStaticRouteMatch(): void
    {
        // Static routes use O(1) hash lookup
        $this->router->addRoute('GET', '/static/path', fn() => 'static');

        $match1 = $this->router->match('GET', '/static/path');
        $this->assertNotFalse($match1);

        // Second call should hit the static route cache
        $match2 = $this->router->match('GET', '/static/path');
        $this->assertNotFalse($match2);
        $this->assertEquals($match1, $match2);
    }

    public function testDynamicRouteWithoutParamsMatchesRepeatedly(): void
    {
        $this->router->addRoute('GET', '/users[/]', fn() => 'users');

        $match1 = $this->router->match('GET', '/users');
        $this->assertNotFalse($match1);

        $match2 = $this->router->match('GET', '/users');
        $this->assertEquals($match1, $match2);
    }

    public function testGetRouteByNameNotFound(): void
    {
        $this->router->addRoute('GET', '/test', fn() => 'test');

        $route = $this->router->getRouteByName('nonexistent');
        $this->assertNull($route);
    }

    public function testNameIndexUpdatedOnRegistration(): void
    {
        $this->router->addRoute('GET', '/first', fn() => 'first')->name('first');

        // Build index
        $route1 = $this->router->getRouteByName('first');
        $this->assertNotNull($route1);

        $this->router->addRoute('GET', '/second', fn() => 'second')->name('second');

        $route2 = $this->router->getRouteByName('second');
        $this->assertNotNull($route2);
    }

    public function testGetRouteByNameReturnsSameInstance(): void
    {
        $this->router->addRoute('GET', '/test', fn() => 'test')->name('test.route');

        $route1 = $this->router->getRouteByName('test.route');
        $this->assertNotNull($route1);

        $route2 = $this->router->getRouteByName('test.route');
        $this->assertSame($route1, $route2);
    }

    public function testPatternWithCaptureGroup(): void
    {
        // Pattern with regular capture group () not (?:)
        $this->router->addRoute('GET', '/{path:(foo|bar)/baz}', fn($path) => $path);

        $match = $this->router->match('GET', '/foo/baz');
        $this->assertNotFalse($match);
        $this->assertEquals(['path' => 'foo/baz'], $match['params']);

        $match = $this->router->match('GET', '/bar/baz');
        $this->assertNotFalse($match);
        $this->assertEquals(['path' => 'bar/baz'], $match['params']);
    }

    public function testHeadFallbackToGetForStaticRoute(): void
    {
        $this->router->addRoute('GET', '/login', fn() => 'login page', [
            'name' => 'login',
            'attributes' => ['permission' => 'login.view'],
        ]);

        $match = $this->router->match('HEAD', '/login');
        $this->assertNotFalse($match);
        $this->assertEquals('login', $match['name']);
        $this->assertEquals([], $match['params']);
        $this->assertSame(['permission' => 'login.view'], $match['attributes']);
    }

    public function testHeadFallbackToGetForDynamicRoute(): void
    {
        $this->router->addRoute('GET', '/users/{id}', fn($id) => "User $id", [
            'name' => 'users.show',
        ]);

        $match = $this->router->match('HEAD', '/users/42');
        $this->assertNotFalse($match);
        $this->assertEquals('users.show', $match['name']);
        $this->assertEquals(['id' => '42'], $match['params']);
    }

    public function testHeadUsesExplicitHeadRouteOverGetFallback(): void
    {
        $this->router->addRoute('GET', '/status', fn() => 'get status');
        $this->router->addRoute('HEAD', '/status', fn() => 'head status', [
            'name' => 'status.head',
        ]);

        $match = $this->router->match('HEAD', '/status');
        $this->assertNotFalse($match);
        $this->assertEquals('status.head', $match['name']);
    }

    public function testHeadReturns404WhenNoGetRoute(): void
    {
        $this->router->addRoute('POST', '/webhook', fn() => 'ok');

        $result = $this->router->match('HEAD', '/webhook');
        $this->assertFalse($result);
    }

    public function testPatternWithTopLevelGroup(): void
    {
        // Pattern with top-level group (not inside parameter or ?:)
        // This exercises the escapePattern branch for regular ( at depth 0
        $this->router->addRoute('GET', '/(admin|user)/profile', fn() => 'profile');

        $match = $this->router->match('GET', '/admin/profile');
        $this->assertNotFalse($match);

        $match = $this->router->match('GET', '/user/profile');
        $this->assertNotFalse($match);

        // Should not match other paths
        $match = $this->router->match('GET', '/guest/profile');
        $this->assertFalse($match);
    }

    public function testLiteralTextInsideOptionalSegmentIsEscaped(): void
    {
        $this->router->addRoute('GET', '/users[.{format:json|xml}]', fn() => 'users');
        $this->router->addRoute('GET', '/api[/v1.0]/ping', fn() => 'ping');
        $this->router->addRoute('GET', '/lang/{id}[/c++]', fn() => 'cpp');

        $this->assertFalse($this->router->match('GET', '/usersXjson'));
        $this->assertSame(['format' => 'json'], $this->router->match('GET', '/users.json')['params'] ?? null);

        $this->assertFalse($this->router->match('GET', '/api/v1-0/ping'));
        $this->assertNotFalse($this->router->match('GET', '/api/v1.0/ping'));
        $this->assertNotFalse($this->router->match('GET', '/api/ping'));

        $this->assertSame(['id' => '5'], $this->router->match('GET', '/lang/5/c++')['params'] ?? null);
        $this->assertFalse($this->router->match('GET', '/lang/5/ccc'));
    }

    public function testLiteralTextBeforeParameterIsEscaped(): void
    {
        $this->router->addRoute('GET', '/a.b/{id}', fn() => 'dot');

        $this->assertNotFalse($this->router->match('GET', '/a.b/1'));
        $this->assertFalse($this->router->match('GET', '/axb/1'));
    }

    public function testRawGroupAtStartOfPath(): void
    {
        $this->router->addRoute('GET', '(en|ru)/about', fn() => 'about');

        $this->assertNotFalse($this->router->match('GET', 'en/about'));
        $this->assertNotFalse($this->router->match('GET', 'ru/about'));
        $this->assertFalse($this->router->match('GET', 'de/about'));
    }

    public function testRawGroupWithBraceQuantifier(): void
    {
        $this->router->addRoute('GET', '/(ab){2}', fn() => 'abab');

        $this->assertNotFalse($this->router->match('GET', '/abab'));
        $this->assertFalse($this->router->match('GET', '/ab'));
    }

    public function testParameterPatternWithClosingDelimitersInCharacterClass(): void
    {
        $this->router->addRoute('GET', '/tags/{tag:[^/)}]+}', fn() => 'tag');

        $this->assertSame(['tag' => 'php'], $this->router->match('GET', '/tags/php')['params'] ?? null);
        $this->assertFalse($this->router->match('GET', '/tags/a)b'));
    }

    public function testDuplicateParameterNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate parameter 'id' in: /{id}/{id}");

        $this->router->addRoute('GET', '/{id}/{id}', fn() => '');
    }

    public function testInvalidParameterNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid parameter name '2' in: /a{2}");

        $this->router->addRoute('GET', '/a{2}', fn() => '');
    }

    public function testInvalidParameterRegexThrowsOnMatch(): void
    {
        $this->router->addRoute('GET', '/{id:a{2,1}}', fn() => '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /{id:a{2,1}}' compiles to an invalid regular expression");

        // Suppress the PCRE compilation warning; the exception carries the route
        @$this->router->match('GET', '/aa');
    }

    public function testDuplicateStaticRouteThrows(): void
    {
        $this->router->addRoute('GET', '/a', fn() => 'first');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /a' is already registered");

        $this->router->addRoute('GET', '/a', fn() => 'second');
    }

    public function testDuplicateDynamicRouteThrows(): void
    {
        $this->router->addRoute('GET', '/users/{id}', fn() => 'first', ['group' => '/api']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET /api/users/{id}' is already registered");

        $this->router->addRoute('GET', '/api/users/{id}', fn() => 'second');
    }

    public function testSamePathWithDifferentMethodsIsAllowed(): void
    {
        $this->router->addRoute('GET', '/users/{id}', fn() => 'show');
        $this->router->addRoute('PUT', '/users/{id}', fn() => 'update');

        $this->assertCount(2, $this->router->getRoutes());
    }

    public function testStaticRouteTakesPrecedenceOverEarlierDynamicRoute(): void
    {
        $this->router->addRoute('GET', '/users/{id}', 'show');
        $this->router->addRoute('GET', '/users/me', 'me');

        $this->assertSame('me', $this->router->match('GET', '/users/me')['handler'] ?? null);
        $this->assertSame('show', $this->router->match('GET', '/users/1')['handler'] ?? null);
    }

    public function testDuplicateNameThrowsOnRegistration(): void
    {
        $this->router->addRoute('GET', '/a', fn() => 'a', ['name' => 'same']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route with name 'same' already exists");

        $this->router->addRoute('GET', '/b', fn() => 'b', ['name' => 'same']);
    }

    public function testRejectedRouteDoesNotKeepItsName(): void
    {
        $this->router->addRoute('GET', '/a', fn() => 'a');

        try {
            $this->router->addRoute('GET', '/a', fn() => 'a', ['name' => 'dup']);
        } catch (InvalidArgumentException) {
        }

        $this->assertNull($this->router->getRouteByName('dup'));
    }

    public function testRouteNamedAfterLookupIsFound(): void
    {
        $route = $this->router->addRoute('GET', '/x', fn() => 'x');
        $this->assertNull($this->router->getRouteByName('x.show'));

        $route->name('x.show');

        $this->assertSame($route, $this->router->getRouteByName('x.show'));
    }

    public function testRenamedRouteIsRemovedFromOldName(): void
    {
        $route = $this->router->addRoute('GET', '/x', fn() => 'x')->name('old');

        $route->name('new');

        $this->assertNull($this->router->getRouteByName('old'));
        $this->assertSame($route, $this->router->getRouteByName('new'));
        $this->assertSame('new', $this->router->match('GET', '/x')['name'] ?? null);
    }

    public function testRejectedRenameKeepsOldName(): void
    {
        $this->router->addRoute('GET', '/a', fn() => 'a')->name('a');
        $route = $this->router->addRoute('GET', '/b', fn() => 'b')->name('b');

        try {
            $route->name('a');
            $this->fail('Expected duplicate name exception');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame('b', $route->getName());
        $this->assertSame($route, $this->router->getRouteByName('b'));
    }

    public function testMiddlewareAddedAfterMatchIsReturned(): void
    {
        $route = $this->router->addRoute('GET', '/p[/]', fn() => 'p');
        $this->router->match('GET', '/p');

        $route->middleware('auth')->attribute('permission', 'p.view');

        $match = $this->router->match('GET', '/p');
        $this->assertNotFalse($match);
        $this->assertSame(['auth'], $match['middlewares']);
        $this->assertSame(['permission' => 'p.view'], $match['attributes']);
    }

    public function testOptionsRoute(): void
    {
        $this->router->addRoute('OPTIONS', '/{any:.*}', fn() => 'preflight');

        $match = $this->router->match('OPTIONS', '/api/users');
        $this->assertNotFalse($match);
        $this->assertSame(['any' => 'api/users'], $match['params']);
    }

    public function testGetAllowedMethods(): void
    {
        $this->router->addRoute('GET', '/users/{id}', fn() => 'show');
        $this->router->addRoute('PUT', '/users/{id}', fn() => 'update');
        $this->router->addRoute('POST', '/users', fn() => 'store');

        $this->assertSame(['GET', 'HEAD', 'PUT'], $this->router->getAllowedMethods('/users/1'));
        $this->assertSame(['POST'], $this->router->getAllowedMethods('/users'));
        $this->assertSame([], $this->router->getAllowedMethods('/missing'));
    }

    public function testAddRouteWithAttributes(): void
    {
        $route = $this->router->addRoute('GET', '/reports', fn() => 'reports', [
            'attributes' => ['permission' => 'reports.view'],
        ]);

        $this->assertSame(['permission' => 'reports.view'], $route->getAttributes());

        $match = $this->router->match('GET', '/reports');
        $this->assertNotFalse($match);
        $this->assertSame(['permission' => 'reports.view'], $match['attributes']);
    }

    public function testMatchReturnsEmptyAttributesWhenNotSet(): void
    {
        $this->router->addRoute('GET', '/users/{id}', fn() => 'show');

        $match = $this->router->match('GET', '/users/1');
        $this->assertNotFalse($match);
        $this->assertSame([], $match['attributes']);
    }

    public function testAttributeReplacesEarlierValue(): void
    {
        $route = $this->router->addRoute('GET', '/reports', fn() => 'reports', [
            'attributes' => ['permission' => 'reports.view', 'plan' => 'basic'],
        ]);

        $route->attribute('plan', 'pro')->attribute('plan', 'enterprise');

        $this->assertSame(['permission' => 'reports.view', 'plan' => 'enterprise'], $route->getAttributes());
    }

    public function testGetAttributeReturnsDefaultOnlyForMissingKey(): void
    {
        $route = $this->router->addRoute('GET', '/p', fn() => 'p')->attribute('cache', null);

        $this->assertNull($route->getAttribute('cache', 60), 'A stored null must not fall back to the default');
        $this->assertSame(60, $route->getAttribute('rate_limit', 60));
        $this->assertNull($route->getAttribute('rate_limit'));
    }

    public function testIntegerAttributeKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route attribute key '0' must be a name, not an integer");

        $this->router->addRoute('GET', '/admin', fn() => 'admin', ['attributes' => ['AuthMiddleware']]);
    }

    public function testIntegerStringAttributeKeyThrows(): void
    {
        $route = $this->router->addRoute('GET', '/p', fn() => 'p');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route attribute key '42' must be a name, not an integer");

        $route->attribute('42', 'x');
    }

    public function testNonCanonicalNumericAttributeKeysStayStrings(): void
    {
        $route = $this->router->addRoute('GET', '/p', fn() => 'p')
            ->attribute('007', 'agent')
            ->attribute('1.5', 'ratio');

        $this->assertSame(['007' => 'agent', '1.5' => 'ratio'], $route->getAttributes());
    }
}
