<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use PHPUnit\Framework\TestCase;
use Solo\Router\RouteCollector;
use InvalidArgumentException;

class RouteCollectorTest extends TestCase
{
    use ResolvesRoutes;

    private RouteCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new RouteCollector();
    }

    public function testGetRoutesReturnsDefinitionsInRegistrationOrder(): void
    {
        $users = $this->collector->get('/users', 'index');
        $admin = null;
        $this->collector->group('/admin', function (RouteCollector $routes) use (&$admin): void {
            $admin = $routes->post('/users', 'store');
        });

        $this->assertSame([$users, $admin], $this->collector->getRoutes());
        $this->assertSame('/admin/users', $admin->fullPath);
    }

    public function testPostRoute(): void
    {
        $this->collector->post('/users', function () {
            return "Create user";
        });
        $match = $this->match($this->collector, 'POST', '/users');
        $this->assertNotFalse($match);
    }

    public function testOptionsRoute(): void
    {
        $this->collector->options('/users', fn() => '');
        $match = $this->match($this->collector, 'OPTIONS', '/users');
        $this->assertNotFalse($match);
    }

    public function testHeadRoute(): void
    {
        $this->collector->head('/health', fn() => '');
        $match = $this->match($this->collector, 'HEAD', '/health');
        $this->assertNotFalse($match);
    }

    public function testPutRoute(): void
    {
        $this->collector->put('/users/{id}', function ($id) {
            return "Update user: $id";
        });
        $match = $this->match($this->collector, 'PUT', '/users/7');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '7'], $match['params']);
    }

    public function testPatchRoute(): void
    {
        $this->collector->patch('/users/{id}', function ($id) {
            return "Patch user: $id";
        });
        $match = $this->match($this->collector, 'PATCH', '/users/3');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '3'], $match['params']);
    }

    public function testDeleteRoute(): void
    {
        $this->collector->delete('/users/{id}', function ($id) {
            return "Delete user: $id";
        });
        $match = $this->match($this->collector, 'DELETE', '/users/9');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '9'], $match['params']);
    }

    public function testNamedRoute(): void
    {
        $this->collector->get('/users/{id}', function ($id) {
            return "User: $id";
        })->name('user.show');

        $route = $this->collector->build()->getRouteByName('user.show');
        $this->assertNotNull($route);
        $this->assertEquals('user.show', $route->name);

        $match = $this->match($this->collector, 'GET', '/users/5');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '5'], $match['params']);
        $this->assertEquals('user.show', $match['name']);
    }

    public function testFluentMiddleware(): void
    {
        $authMiddleware = fn() => 'auth';
        $logMiddleware = fn() => 'log';

        $this->collector->get('/admin', function () {
            return 'Admin';
        })->name('admin')->middleware($authMiddleware, $logMiddleware);

        $match = $this->match($this->collector, 'GET', '/admin');
        $this->assertNotFalse($match);
        $this->assertCount(2, $match['middlewares']);
        $this->assertContains($authMiddleware, $match['middlewares']);
        $this->assertContains($logMiddleware, $match['middlewares']);

        $route = $this->collector->build()->getRouteByName('admin');
        $this->assertNotNull($route);
        $this->assertEquals('admin', $route->name);
    }

    public function testFluentAttribute(): void
    {
        $this->collector->get('/reports', 'reports')
            ->name('reports')
            ->attribute('permission', 'reports.view')
            ->attribute('plan', 'pro');

        $match = $this->match($this->collector, 'GET', '/reports');
        $this->assertNotFalse($match);
        $this->assertSame(['permission' => 'reports.view', 'plan' => 'pro'], $match['attributes']);
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
        $this->collector->build();
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

        $match = $this->match($this->collector, 'GET', '/admin/dashboard');
        $this->assertNotFalse($match);
        $this->assertContains($middleware, $match['middlewares']);
    }

    public function testNestedRouteGroups(): void
    {
        $this->collector->group('/api', function ($router) {
            $router->group('/v1', function ($router) {
                $router->get('/users/{id}', function ($id) {
                    return "User: $id";
                });
            });
        });

        $match = $this->match($this->collector, 'GET', '/api/v1/users/123');
        $this->assertNotFalse($match);
        $this->assertEquals(['id' => '123'], $match['params']);
    }

    public function testNestedGroupMiddlewareCanonicalOrder(): void
    {
        $outer = fn() => 'outer';
        $inner = fn() => 'inner';
        $route = fn() => 'route';

        $this->collector->group('/api', function ($router) use ($inner, $route) {
            $router->group('/v1', function ($router) use ($route) {
                $router->get('/ping', fn() => 'pong')->middleware($route);
            }, [$inner]);
        }, [$outer]);

        $match = $this->match($this->collector, 'GET', '/api/v1/ping');
        $this->assertNotFalse($match);
        $this->assertSame(
            [$outer, $inner, $route],
            $match['middlewares'],
            'Outer group middleware must precede inner group middleware, route middleware last'
        );
    }

    public function testGroupMiddlewareDoesNotLeakAcrossSiblings(): void
    {
        $a = fn() => 'a';
        $b = fn() => 'b';

        $this->collector->group('/a', function ($router) {
            $router->get('/x', fn() => 'x');
        }, [$a], ['permission' => 'a']);

        $this->collector->group('/b', function ($router) {
            $router->get('/y', fn() => 'y');
        }, [$b]);

        $matchA = $this->match($this->collector, 'GET', '/a/x');
        $matchB = $this->match($this->collector, 'GET', '/b/y');

        $this->assertSame([$a], $matchA['middlewares']);
        $this->assertSame([$b], $matchB['middlewares']);
        $this->assertSame(['permission' => 'a'], $matchA['attributes']);
        $this->assertSame([], $matchB['attributes']);
    }

    public function testNestedGroupAndRouteAttributesOverrideByKey(): void
    {
        $this->collector->group('/admin', function (RouteCollector $router): void {
            $router->get('/users', 'users');

            $router->group('/reports', function (RouteCollector $router): void {
                $router->get('/daily', 'daily');
                $router->get('/export', 'export')->attribute('plan', 'pro');
            }, attributes: ['permission' => 'reports.view', 'tags' => ['reports']]);
        }, attributes: ['permission' => 'admin', 'plan' => 'basic', 'tags' => ['admin']]);

        $this->assertSame(
            ['permission' => 'admin', 'plan' => 'basic', 'tags' => ['admin']],
            $this->match($this->collector, 'GET', '/admin/users')['attributes'] ?? null
        );
        $this->assertSame(
            ['permission' => 'reports.view', 'plan' => 'basic', 'tags' => ['reports']],
            $this->match($this->collector, 'GET', '/admin/reports/daily')['attributes'] ?? null,
            'Inner group must replace outer values by key, not merge them'
        );
        $this->assertSame(
            ['permission' => 'reports.view', 'plan' => 'pro', 'tags' => ['reports']],
            $this->match($this->collector, 'GET', '/admin/reports/export')['attributes'] ?? null,
            'Route attribute must replace the group value'
        );
    }

    public function testGroupRejectsAttributesPassedAsMiddleware(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Group '/billing' middleware has key 'permission'; pass attributes as the fourth argument"
        );

        $this->collector->group('/billing', function (RouteCollector $router): void {
            $router->get('/invoices', 'index');
        }, ['permission' => 'billing.manage']);
    }

    public function testGroupRejectsMiddlewarePassedAsAttributes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route attribute key '0' must be a name, not an integer");

        // Rejected on entry, even before the group registers any route
        $this->collector->group('/admin', function (): void {
        }, [], ['AuthMiddleware']);
    }

    public function testAddRouteRejectsMiddlewareWithStringKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Route 'GET /admin/users' middleware has key 'permission'; pass attributes in the 'attributes' option"
        );

        $this->collector->group('/admin', function (RouteCollector $router): void {
            $router->addRoute('GET', '/users', 'users', ['middlewares' => ['permission' => 'users.view']]);
        });
    }

    public function testAddRouteMiddlewareIsAList(): void
    {
        $this->collector->group('/admin', function (RouteCollector $router): void {
            $router->addRoute('GET', '/users', 'users', ['middlewares' => array_filter([null, 'auth', null, 'log'])]);
        }, ['group']);

        $this->assertSame(
            ['group', 'auth', 'log'],
            $this->match($this->collector, 'GET', '/admin/users')['middlewares'] ?? null
        );
    }

    public function testGroupAcceptsMiddlewareWithNonSequentialIntegerKeys(): void
    {
        $this->collector->group('/admin', function (RouteCollector $router): void {
            $router->get('/users', 'users');
        }, array_filter([null, 'auth']));

        $this->assertSame(['auth'], $this->match($this->collector, 'GET', '/admin/users')['middlewares'] ?? null);
    }

    public function testGroupRestoresStateWhenCallbackThrows(): void
    {
        try {
            $this->collector->group('/admin', function (): void {
                throw new \RuntimeException('failed');
            }, ['auth'], ['permission' => 'admin']);
        } catch (\RuntimeException) {
        }

        $this->collector->get('/public', 'public');

        $match = $this->match($this->collector, 'GET', '/public');
        $this->assertNotFalse($match);
        $this->assertSame([], $match['middlewares']);
        $this->assertSame([], $match['attributes']);
        $this->assertFalse($this->match($this->collector, 'GET', '/admin/public'));
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
        $result = $this->match($this->collector, 'GET', '/admin/users');
        $this->assertNotFalse($result);
        $this->assertEquals([], $result['params']); // Empty lang is filtered out

        // Should match with language prefix
        $result = $this->match($this->collector, 'GET', '/en/admin/users');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'en/'], $result['params']);

        $result = $this->match($this->collector, 'GET', '/ua/admin/posts');
        $this->assertNotFalse($result);
        $this->assertEquals(['lang' => 'ua/'], $result['params']);

        // Should NOT match with invalid language code (3 letters)
        $result = $this->match($this->collector, 'GET', '/eng/admin/users');
        $this->assertFalse($result);
    }

    public function testOptionalLanguageGroupWithOptionalSlash(): void
    {
        $this->collector->group('[/{lang:[a-z]{2}}]', function (RouteCollector $router): void {
            $router->get('[/]', 'home');
            $router->get('/documentation', 'docs');
        });

        $match = $this->match($this->collector, 'GET', '');
        $this->assertNotFalse($match);
        $this->assertSame('home', $match['handler']);
        $this->assertSame([], $match['params']);

        $match = $this->match($this->collector, 'GET', '/');
        $this->assertNotFalse($match);
        $this->assertSame('home', $match['handler']);
        $this->assertSame([], $match['params']);

        $match = $this->match($this->collector, 'GET', '/en');
        $this->assertNotFalse($match);
        $this->assertSame('home', $match['handler']);
        $this->assertSame(['lang' => 'en'], $match['params']);

        $match = $this->match($this->collector, 'GET', '/en/');
        $this->assertNotFalse($match);
        $this->assertSame('home', $match['handler']);
        $this->assertSame(['lang' => 'en'], $match['params']);

        $match = $this->match($this->collector, 'GET', '/documentation');
        $this->assertNotFalse($match);
        $this->assertSame('docs', $match['handler']);
        $this->assertSame([], $match['params']);

        $match = $this->match($this->collector, 'GET', '/en/documentation');
        $this->assertNotFalse($match);
        $this->assertSame('docs', $match['handler']);
        $this->assertSame(['lang' => 'en'], $match['params']);
    }
}
