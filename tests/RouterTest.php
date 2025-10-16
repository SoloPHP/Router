<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use PHPUnit\Framework\TestCase;
use Solo\Router\Router;
use Solo\Router\Matchers\RouteMatcher;
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
    }

    public function testMatchRouteNotFound(): void
    {
        $this->router->addRoute('GET', '/users/{id}', function ($id) {
            return "User: $id";
        });

        $result = $this->router->match('POST', '/api/users/123');
        $this->assertFalse($result);
    }

    public function testMatchRouteWithOptionalSegments(): void
    {
        $this->router->addRoute('GET', '/posts[/{page}]', function ($page = null) {
            return "Posts: $page";
        });

        $result = $this->router->match('GET', '/posts');
        $this->assertNotFalse($result);

        $result = $this->router->match('GET', '/posts/2');
        $this->assertNotFalse($result);
        $this->assertEquals(['page' => '2'], $result['params']);
    }

    public function testMatchRouteWithRegexPattern(): void
    {
        $this->router->addRoute('GET', '/users/{id:[0-9]+}', function ($id) {
            return "User: $id";
        });

        $result = $this->router->match('GET', '/users/123');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123'], $result['params']);
    }

    public function testOptionalParametersInMiddleShouldFail(): void
    {
        // This test is no longer valid as optional segments in the middle are now supported
        // Keeping it as a positive test instead
        $this->router->addRoute('GET', '/users[/{id}]/posts', function ($id = null) {
            return "User posts: $id";
        });

        // Without optional parameter
        $result = $this->router->match('GET', '/users/posts');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // With optional parameter
        $result = $this->router->match('GET', '/users/123/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123'], $result['params']);
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

    public function testOptionalSegmentInMiddle(): void
    {
        $this->router->addRoute('GET', '/users[/{id}]/posts', function ($id = null) {
            return "User posts for: " . ($id ?? 'all');
        });

        // Without optional parameter
        $result = $this->router->match('GET', '/users/posts');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']);

        // With optional parameter
        $result = $this->router->match('GET', '/users/123/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123'], $result['params']);
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

    public function testRouteMatcherWithCache(): void
    {
        $matcher = new RouteMatcher();

        $this->router->addRoute('GET', '/users/{id}', fn($id) => "User $id");
        $this->router->addRoute('GET', '/posts/{id}', fn($id) => "Post $id");

        $routes = $this->router->getRoutes();

        // First request - not from cache
        $result1 = $matcher->match($routes, 'GET', '/users/1');
        $this->assertNotNull($result1);
        $this->assertEquals(['id' => '1'], $result1['params']);

        // Second request - from cache
        $result2 = $matcher->match($routes, 'GET', '/users/1');
        $this->assertNotNull($result2);
        $this->assertEquals($result1, $result2);

        // Fill cache
        $matcher->match($routes, 'GET', '/posts/2');
        $matcher->match($routes, 'GET', '/posts/3');
        $matcher->match($routes, 'GET', '/posts/4');

        // Clear cache
        $matcher->clearCache();
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

    public function testInvalidBracketsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unmatched opening bracket');

        $this->router->addRoute('GET', '/users[/{id}', fn() => '');
        $this->router->match('GET', '/users');
    }

    public function testUnmatchedClosingBracketThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unmatched closing bracket');

        $this->router->addRoute('GET', '/users/{id}]', fn() => '');
        $this->router->match('GET', '/users/123');
    }
}
