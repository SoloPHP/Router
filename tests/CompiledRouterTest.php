<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use Error;
use InvalidArgumentException;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;
use Solo\Router\Results\NotFound;
use Solo\Router\Route;
use Solo\Router\RouteCollector;
use Solo\Router\RouteDefinition;
use Solo\Router\Router;

final class CompiledRouterTest extends TestCase
{
    public function testResolveDistinguishesMatchedNotFoundAndMethodNotAllowed(): void
    {
        $builder = new RouteCollector();
        $builder->get('/users/{id}', 'show')->name('users.show');
        $router = $builder->build();

        $matched = $router->resolve('GET', '/users/42');
        $this->assertInstanceOf(Matched::class, $matched);
        $this->assertInstanceOf(Route::class, $matched->route);
        $this->assertSame('show', $matched->route->handler);
        $this->assertSame('users.show', $matched->route->name);
        $this->assertSame(['id' => '42'], $matched->params);
        $this->assertInstanceOf(NotFound::class, $router->resolve('GET', '/missing'));

        $notAllowed = $router->resolve('POST', '/users/42');
        $this->assertInstanceOf(MethodNotAllowed::class, $notAllowed);
        $this->assertSame(['GET', 'HEAD'], $notAllowed->allowedMethods);
    }

    public function testShortcutsAcceptOpaqueHandlerObjects(): void
    {
        $builder = new RouteCollector();
        $handler = new \stdClass();
        foreach (['get', 'head', 'post', 'put', 'patch', 'delete', 'options'] as $method) {
            $builder->$method('/handler', $handler);
        }
        $router = $builder->build();
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $result = $router->resolve($method, '/handler');
            $this->assertInstanceOf(Matched::class, $result);
            $this->assertSame($handler, $result->route->handler);
        }
    }

    public function testBuilderAndRuntimeHaveSeparateResponsibilities(): void
    {
        $builder = new RouteCollector();
        $definition = $builder->get('/test', 'test');
        $router = $builder->build();

        $this->assertInstanceOf(RouteDefinition::class, $definition);
        $this->assertNotInstanceOf(Router::class, $builder);
        $this->assertFalse(method_exists($builder, 'resolve'));
        $this->assertFalse(method_exists($builder, 'match'));
        $this->assertFalse(method_exists($router, 'addRoute'));
        $this->assertFalse(method_exists($router, 'match'));
        $this->assertFalse(method_exists($router->getRoutes()[0], 'name'));
        $this->assertFalse(method_exists($router->getRoutes()[0], 'middleware'));
        $this->assertFalse(method_exists($router->getRoutes()[0], 'attribute'));
    }

    public function testAddingRoutesDoesNotChangeAnExistingRuntime(): void
    {
        $builder = new RouteCollector();
        $builder->get('/users/{id}', 'users');
        $first = $builder->build();
        $builder->post('/users/{id}', 'update');
        $builder->get('/reports', 'reports')->name('reports');
        $second = $builder->build();

        $this->assertInstanceOf(NotFound::class, $first->resolve('GET', '/reports'));
        $this->assertInstanceOf(MethodNotAllowed::class, $first->resolve('POST', '/users/42'));
        $this->assertSame(['GET', 'HEAD'], $first->getAllowedMethods('/users/42'));
        $this->assertNull($first->getRouteByName('reports'));
        $this->assertCount(1, $first->getRoutes());

        $this->assertInstanceOf(Matched::class, $second->resolve('GET', '/reports'));
        $this->assertInstanceOf(Matched::class, $second->resolve('POST', '/users/42'));
        $this->assertSame(['GET', 'HEAD', 'POST'], $second->getAllowedMethods('/users/42'));
        $this->assertCount(3, $second->getRoutes());
    }

    public function testChangingReturnedRouteListCannotChangeRuntime(): void
    {
        $builder = new RouteCollector();
        $builder->get('/users', 'users');
        $router = $builder->build();
        $routes = $router->getRoutes();
        array_pop($routes);

        $this->assertCount(1, $router->getRoutes());
        $this->assertInstanceOf(Matched::class, $router->resolve('GET', '/users'));
    }

    public function testSnapshotPreservesOpaqueObjectIdentity(): void
    {
        $metadata = new \stdClass();
        $handler = fn() => 'users';
        $builder = new RouteCollector();
        $builder->get('/users', $handler)->attribute('metadata', $metadata);
        $route = $builder->build()->getRoutes()[0];

        $this->assertSame($handler, $route->handler);
        $this->assertSame($metadata, $route->getAttribute('metadata'));
    }

    public function testRepeatedResolutionKeepsParametersSeparate(): void
    {
        $builder = new RouteCollector();
        $builder->get('/users/{id}', 'users');
        $router = $builder->build();

        $first = $router->resolve('GET', '/users/1');
        $second = $router->resolve('GET', '/users/2');
        $this->assertInstanceOf(Matched::class, $first);
        $this->assertInstanceOf(Matched::class, $second);
        $this->assertSame(['id' => '1'], $first->params);
        $this->assertSame(['id' => '2'], $second->params);
        $this->assertSame($first->route, $second->route);
    }

    public function testRuntimeRouteNameCannotBeChanged(): void
    {
        $builder = new RouteCollector();
        $builder->get('/users', 'users')->name('users');
        $route = $builder->build()->getRouteByName('users');

        $this->expectException(Error::class);
        $route->name = 'renamed';
    }

    public function testRuntimeRouteAttributesCannotBeChanged(): void
    {
        $builder = new RouteCollector();
        $builder->get('/users', 'users')->attribute('plan', 'basic');
        $route = $builder->build()->getRoutes()[0];

        $this->expectException(Error::class);
        $route->attributes['plan'] = 'pro';
    }

    public function testRuntimeRouteGetAttributePreservesStoredNull(): void
    {
        $builder = new RouteCollector();
        $builder->get('/users', 'users')->attribute('cache', null);
        $route = $builder->build()->getRoutes()[0];

        $this->assertNull($route->getAttribute('cache', 60));
        $this->assertSame(60, $route->getAttribute('missing', 60));
        $this->assertNull($route->getAttribute('missing'));
    }

    public function testGenericAddRouteInheritsNestedGroupConfiguration(): void
    {
        $builder = new RouteCollector();
        $builder->group('/api', function (RouteCollector $routes): void {
            $routes->group('/v1', function (RouteCollector $routes): void {
                $routes->addRoute('post', '/users/{id}', 'update', [
                    'group' => '/internal',
                    'name' => 'users.update',
                    'middlewares' => ['route'],
                    'attributes' => ['permission' => 'users.update', 'local' => true],
                ]);
            }, ['inner'], ['plan' => 'pro']);
        }, ['outer'], ['permission' => 'api', 'plan' => 'basic']);
        $builder->get('/public', 'public');
        $router = $builder->build();

        $result = $router->resolve('POST', '/api/v1/internal/users/42');
        $this->assertInstanceOf(Matched::class, $result);
        $this->assertSame(['id' => '42'], $result->params);
        $this->assertSame('/api/v1/internal/users/{id}', $result->route->path);
        $this->assertSame(['outer', 'inner', 'route'], $result->route->middlewares);
        $this->assertSame(
            ['permission' => 'users.update', 'plan' => 'pro', 'local' => true],
            $result->route->attributes
        );
        $this->assertSame($result->route, $router->getRouteByName('users.update'));
        $this->assertSame([], $router->resolve('GET', '/public')->route->attributes);
        $this->assertSame([], $router->resolve('GET', '/public')->route->middlewares);
    }

    public function testExplicitHeadDynamicRoutePrecedesGetStaticFallback(): void
    {
        $builder = new RouteCollector();
        $builder->get('/users/me', 'get-static');
        $builder->head('/users/{id}', 'head-dynamic');
        $router = $builder->build();

        $result = $router->resolve('HEAD', '/users/me');
        $this->assertInstanceOf(Matched::class, $result);
        $this->assertSame('head-dynamic', $result->route->handler);
        $this->assertSame(['id' => 'me'], $result->params);
    }

    public function testStaticRouteWithDifferentMethodDoesNotHideDynamicRoute(): void
    {
        $builder = new RouteCollector();
        $builder->post('/users/me', 'post-static');
        $builder->get('/users/{id}', 'get-dynamic');
        $router = $builder->build();

        $result = $router->resolve('GET', '/users/me');
        $this->assertInstanceOf(Matched::class, $result);
        $this->assertSame('get-dynamic', $result->route->handler);
        $this->assertSame(['id' => 'me'], $result->params);
    }

    public function testMethodNotAllowedCombinesAllOverlappingPaths(): void
    {
        $builder = new RouteCollector();
        $builder->options('/api/users/42', 'options');
        $builder->put('/api/{path:.*}', 'put');
        $builder->post('/{path:.*}', 'post');
        $builder->get('/api/users/{id}', 'get');
        $builder->delete('/api/users/{id}', 'delete');
        $router = $builder->build();

        $result = $router->resolve('PATCH', '/api/users/42');
        $this->assertInstanceOf(MethodNotAllowed::class, $result);
        $this->assertSame(['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS'], $result->allowedMethods);
        $this->assertSame($result->allowedMethods, $router->getAllowedMethods('/api/users/42'));
    }

    public function testEmptyRuntimeReturnsNotFound(): void
    {
        $router = (new RouteCollector())->build();

        $this->assertInstanceOf(NotFound::class, $router->resolve('GET', '/'));
        $this->assertSame([], $router->getAllowedMethods('/'));
        $this->assertSame([], $router->getRoutes());
        $this->assertNull($router->getRouteByName('missing'));
    }

    #[DataProvider('runtimeRegexOperations')]
    public function testRuntimeRegexFailureIsNotReportedAsARoutingMiss(string $operation): void
    {
        $builder = new RouteCollector();
        $builder->get('/{value:(a+)+}', 'value');
        $router = $builder->build();
        $uri = '/' . str_repeat('a', 64) . '!';
        $previousLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot match GET routes: Backtrack limit exhausted');

            if ($operation === 'allowed-methods') {
                $router->getAllowedMethods($uri);
            } else {
                $router->resolve($operation, $uri);
            }
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }
    }

    public static function runtimeRegexOperations(): iterable
    {
        yield 'matching requested method' => ['GET'];
        yield 'collecting methods after method mismatch' => ['POST'];
        yield 'explicit allowed methods lookup' => ['allowed-methods'];
    }

    #[DataProvider('invalidPatterns')]
    public function testInvalidPatternsAreRejectedAtBuild(string $pattern): void
    {
        $builder = new RouteCollector();
        $builder->get('/valid', 'valid');
        $builder->post($pattern, 'invalid');

        $this->expectException(InvalidArgumentException::class);
        $builder->build();
    }

    public static function invalidPatterns(): iterable
    {
        yield 'unmatched optional' => ['/broken[/{id}'];
        yield 'duplicate parameter' => ['/{id}/{id}'];
        yield 'invalid parameter name' => ['/{2}'];
        yield 'invalid regex' => ['/unrelated/{id:a{2,1}}'];
    }

    public function testRouteThatPcreCannotCompileIsRejectedByName(): void
    {
        // PCRE allows at most 65535 capturing groups in a pattern
        $path = implode('', array_map(static fn(int $i) => "/{p$i}", range(0, 65535)));
        $builder = new RouteCollector();
        $builder->get('/users/{id}', 'users');
        $builder->get($path, 'too many parameters');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'GET $path' cannot be compiled: preg_match(): Compilation failed:");
        $builder->build();
    }

    public function testDistinctRegexGroupsAreLimited(): void
    {
        $builder = new RouteCollector();
        $builder->get(implode('', array_map(static fn(int $i) => "[/$i]", range(0, 255 * 255))), 'groups');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the limit of 65025 distinct regex groups in one router');
        $builder->build();
    }

    public function testDistinctRegexGroupsUpToTheLimitAreAccepted(): void
    {
        $builder = new RouteCollector();
        $builder->get(implode('', array_map(static fn(int $i) => "[/$i]", range(1, 255 * 255))), 'groups');

        $message = '';
        try {
            $builder->build();
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage(); // PCRE rejects a pattern this large unless built with a larger link size
        }
        $this->assertStringNotContainsString('exceeds the limit', $message);
    }

    public function testRoutesWithHundredsOfDistinctRegexesResolve(): void
    {
        $builder = new RouteCollector();
        for ($i = 1; $i <= 600; $i++) {
            $builder->get("/{v:x{{$i}}}/a", "a$i");
            $builder->get("/{v:x{{$i}}}/b", "b$i");
        }
        $router = $builder->build();

        // Placeholders encode the group number in two bytes; these cross their boundaries
        foreach ([1, 254, 255, 256, 509, 510, 511, 600] as $i) {
            foreach (['a', 'b'] as $suffix) {
                $result = $router->resolve('GET', '/' . str_repeat('x', $i) . "/$suffix");
                $this->assertInstanceOf(Matched::class, $result, "$i/$suffix");
                $this->assertSame("$suffix$i", $result->route->handler);
                $this->assertSame(['v' => str_repeat('x', $i)], $result->params);
            }
        }
    }
}
