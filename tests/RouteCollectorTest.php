<?php

declare(strict_types=1);

namespace Solo\Router\Tests;

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

        $routes = $this->collector->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('GET', $routes[0]['method']);
        $this->assertEquals('/users/{id}', $routes[0]['path']);
    }

    public function testPostRoute(): void
    {
        $this->collector->post('/users', function () {
            return "Create user";
        });

        $routes = $this->collector->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('POST', $routes[0]['method']);
    }

    public function testPutRoute(): void
    {
        $this->collector->put('/users/{id}', function ($id) {
            return "Update user: $id";
        });

        $routes = $this->collector->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('PUT', $routes[0]['method']);
    }

    public function testPatchRoute(): void
    {
        $this->collector->patch('/users/{id}', function ($id) {
            return "Patch user: $id";
        });

        $routes = $this->collector->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('PATCH', $routes[0]['method']);
    }

    public function testDeleteRoute(): void
    {
        $this->collector->delete('/users/{id}', function ($id) {
            return "Delete user: $id";
        });

        $routes = $this->collector->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('DELETE', $routes[0]['method']);
    }

    public function testNamedRoute(): void
    {
        $this->collector->get('/users/{id}', function ($id) {
            return "User: $id";
        })->name('user.show');

        $routes = $this->collector->getRoutes();
        $this->assertArrayHasKey('user.show', $routes);
        $this->assertEquals('GET', $routes['user.show']['method']);
    }

    public function testNamedRouteWithoutPreviousRoute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot name route: no routes have been added');

        $this->collector->name('user.show');
    }

    public function testNamedRouteWithDuplicateName(): void
    {
        $this->collector->get('/users/{id}', function ($id) {
            return "User: $id";
        })->name('user.show');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route with name 'user.show' already exists");

        $this->collector->get('/users', function () {
            return "Users list";
        })->name('user.show');
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

        $routes = $this->collector->getRoutes();
        $this->assertCount(2, $routes);

        // Check that both routes have the /api prefix
        foreach ($routes as $route) {
            $this->assertEquals('/api', $route['group']);
        }
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

        $routes = $this->collector->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertContains($middleware, $routes[0]['middleware']);
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

        $routes = $this->collector->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('/api/v1', $routes[0]['group']);
    }

    public function testMatchRouteWithGroup(): void
    {
        $this->collector->group('/api', function ($router) {
            $router->get('/users/{id}', function ($id) {
                return "User: $id";
            });
        });

        $result = $this->collector->matchRoute('GET', '/api/users/123');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123'], $result['args']);
    }
}
