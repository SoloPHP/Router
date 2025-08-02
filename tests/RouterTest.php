<?php

declare(strict_types=1);

namespace Solo\Router\Tests;

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
        $this->router->addRoute('GET', '/api', '/users/{id}', function ($id) {
            return "User: $id";
        });

        $routes = $this->router->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('GET', $routes[0]['method']);
        $this->assertEquals('/api', $routes[0]['group']);
        $this->assertEquals('/users/{id}', $routes[0]['path']);
    }

    public function testAddRouteWithInvalidMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported HTTP method: INVALID');

        $this->router->addRoute('INVALID', '/api', '/users', function () {
        });
    }

    public function testMatchRoute(): void
    {
        $this->router->addRoute('GET', '/api', '/users/{id}', function ($id) {
            return "User: $id";
        });

        $result = $this->router->matchRoute('GET', '/api/users/123');

        $this->assertNotFalse($result);
        $this->assertEquals('GET', $result['method']);
        $this->assertEquals('/api', $result['group']);
        $this->assertEquals(['id' => '123'], $result['args']);
    }

    public function testMatchRouteNotFound(): void
    {
        $this->router->addRoute('GET', '/api', '/users/{id}', function ($id) {
            return "User: $id";
        });

        $result = $this->router->matchRoute('POST', '/api/users/123');
        $this->assertFalse($result);
    }

    public function testMatchRouteWithOptionalSegments(): void
    {
        $this->router->addRoute('GET', '/api', '/posts[/{page}]', function ($page = null) {
            return "Posts: $page";
        });

        $result = $this->router->matchRoute('GET', '/api/posts');
        $this->assertNotFalse($result);

        $result = $this->router->matchRoute('GET', '/api/posts/2');
        $this->assertNotFalse($result);
        $this->assertEquals(['page' => '2'], $result['args']);
    }

    public function testMatchRouteWithRegexPattern(): void
    {
        $this->router->addRoute('GET', '/api', '/users/{id:[0-9]+}', function ($id) {
            return "User: $id";
        });

        $result = $this->router->matchRoute('GET', '/api/users/123');
        $this->assertNotFalse($result);
        $this->assertEquals(['id' => '123'], $result['args']);
    }
}
