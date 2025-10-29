<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use PHPUnit\Framework\TestCase;
use Solo\Router\RouteCollector;
use InvalidArgumentException;

class RouteCollectorTest extends TestCase
{
    private RouteCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new RouteCollector();
    }

    public function testGetRoute(): void
    {
        $this->collector->get('/users/{id}', function ($id) {
            return "User: $id";
        });
        $match = $this->collector->match('GET', '/users/1');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '1'], $match['params']);
    }

    public function testPostRoute(): void
    {
        $this->collector->post('/users', function () {
            return "Create user";
        });
        $match = $this->collector->match('POST', '/users');
        $this->assertNotFalse($match);
    }

    public function testPutRoute(): void
    {
        $this->collector->put('/users/{id}', function ($id) {
            return "Update user: $id";
        });
        $match = $this->collector->match('PUT', '/users/7');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '7'], $match['params']);
    }

    public function testPatchRoute(): void
    {
        $this->collector->patch('/users/{id}', function ($id) {
            return "Patch user: $id";
        });
        $match = $this->collector->match('PATCH', '/users/3');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '3'], $match['params']);
    }

    public function testDeleteRoute(): void
    {
        $this->collector->delete('/users/{id}', function ($id) {
            return "Delete user: $id";
        });
        $match = $this->collector->match('DELETE', '/users/9');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '9'], $match['params']);
    }

    public function testNamedRoute(): void
    {
        $this->collector->get('/users/{id}', function ($id) {
            return "User: $id";
        }, [], 'user.show');

        $match = $this->collector->match('GET', '/users/5');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '5'], $match['params']);
    }

    public function testNamedRouteWithDuplicateName(): void
    {
        $this->collector->get('/users/{id}', function ($id) {
            return "User: $id";
        }, [], 'user.show');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route with name 'user.show' already exists");

        $this->collector->get('/users', function () {
            return "Users list";
        }, [], 'user.show');
    }

    public function testRouteGroup(): void
    {
        $this->collector->group('/api', function ($router) {
            $router->get('/users', function () {
                return "Users list";
            });
            $router->post('/users', function () {
                return "Create user";
            });
        });
        $m1 = $this->collector->match('GET', '/api/users');
        $m2 = $this->collector->match('POST', '/api/users');
        $this->assertNotFalse($m1);
        $this->assertNotFalse($m2);
    }

    public function testRouteGroupWithMiddleware(): void
    {
        $middleware = function () {
            return "auth";
        };

        $this->collector->group('/admin', function ($router) {
            $router->get('/dashboard', function () {
                return "Dashboard";
            });
        }, [$middleware]);

        $match = $this->collector->match('GET', '/admin/dashboard');
        $this->assertNotFalse($match);
        $this->assertContains($middleware, $match['middlewares']);
    }

    public function testNestedRouteGroups(): void
    {
        $this->collector->group('/api', function ($router) {
            $router->group('/v1', function ($router) {
                $router->get('/users', function () {
                    return "Users v1";
                });
            });
        });
        $match = $this->collector->match('GET', '/api/v1/users');
        $this->assertNotFalse($match);
    }

    public function testMatchRouteWithGroup(): void
    {
        $this->collector->group('/api', function ($router) {
            $router->get('/users/{id}', function ($id) {
                return "User: $id";
            });
        });

        $result = $this->collector->match('GET', '/api/users/123');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123'], $result['params']);
    }

    public function testOptionalLanguagePrefixWithSingleGroup(): void
    {
        // Single group with optional language prefix
        $this->collector->group('/{lang:(?:[a-z]{2}\/)?}', function ($router) {
            $router->get('admin/users', function () {
                return 'Admin users';
            });
            $router->get('admin/posts', function () {
                return 'Admin posts';
            });
        });

        // Should match without language prefix
        $result = $this->collector->match('GET', '/admin/users');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']); // Empty lang is filtered out

        // Should match with language prefix
        $result = $this->collector->match('GET', '/en/admin/users');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en/'], $result['params']);

        $result = $this->collector->match('GET', '/ua/admin/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'ua/'], $result['params']);

        // Should NOT match with invalid language code (3 letters)
        $result = $this->collector->match('GET', '/eng/admin/users');
        $this->assertFalse($result);
    }

    public function testOptionalLanguageGroupWithOptionalSlash(): void
    {
        $this->collector->group('[/{lang:[a-z]{2}}]', function (RouteCollector $router): void {
            $router->get('[/]', 'home');
            $router->get('/documentation', 'docs');
        });

        $match = $this->collector->match('GET', '');
        $this->assertNotFalse($match);
        $this->assertSame('home', $match['handler']);
        $this->assertSame([], $match['params']);

        $match = $this->collector->match('GET', '/');
        $this->assertNotFalse($match);
        $this->assertSame('home', $match['handler']);
        $this->assertSame([], $match['params']);

        $match = $this->collector->match('GET', '/en');
        $this->assertNotFalse($match);
        $this->assertSame('home', $match['handler']);
        $this->assertSame(['lang' => 'en'], $match['params']);

        $match = $this->collector->match('GET', '/en/');
        $this->assertNotFalse($match);
        $this->assertSame('home', $match['handler']);
        $this->assertSame(['lang' => 'en'], $match['params']);

        $match = $this->collector->match('GET', '/documentation');
        $this->assertNotFalse($match);
        $this->assertSame('docs', $match['handler']);
        $this->assertSame([], $match['params']);

        $match = $this->collector->match('GET', '/en/documentation');
        $this->assertNotFalse($match);
        $this->assertSame('docs', $match['handler']);
        $this->assertSame(['lang' => 'en'], $match['params']);
    }
}
