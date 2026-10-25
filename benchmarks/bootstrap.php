<?php

declare(strict_types=1);

/*
 * Shared setup for lookup.php and build.php.
 *
 * BENCHMARK_VENDOR: Composer vendor directory with the compared libraries (default: ./vendor)
 * SOLO_SRC: Solo Router sources to measure (default: ../src), e.g. a previous version
 */

use GuzzleHttp\Psr7\HttpFactory;
use Slim\CallableResolver;
use Slim\Routing\Dispatcher as SlimDispatcher;
use Slim\Routing\RouteCollector as SlimRoutes;
use Slim\Routing\RoutingResults;
use Solo\Router\RouteCollector;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection as SymfonyRoutes;

$vendor = getenv('BENCHMARK_VENDOR') ?: __DIR__ . '/vendor';
require $vendor . '/autoload.php';

$soloSource = rtrim(getenv('SOLO_SRC') ?: __DIR__ . '/../src', '/') . '/';
spl_autoload_register(static function (string $class) use ($soloSource): void {
    if (str_starts_with($class, 'Solo\\Router\\')) {
        $file = $soloSource . str_replace('\\', '/', substr($class, strlen('Solo\\Router\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);

const LAYOUTS = ['separate', 'shared', 'tenant', 'locale', 'optional-locale', 'numeric'];
const PROFILES = ['static', 'dynamic', 'mixed', 'last', 'miss', '405', 'head'];
const LIBRARIES = ['solo', 'leaf', 'symfony', 'slim'];

/** Libraries support different route grammars and request APIs. */
function supports(string $library, string $layout, string $profile): bool
{
    return match ($library) {
        'solo' => true,
        'solo-cached' => class_exists(Solo\Router\RouteCache::class),
        'leaf' => in_array($layout, ['separate', 'shared'], true) && !in_array($profile, ['405', 'head'], true),
        default => $layout !== 'optional-locale',
    };
}

/**
 * Exactly $count GET routes: a resource endpoint and the same endpoint with an ID, interleaved.
 *
 * @return list<array{path: string, requirements: array<string, string>, name: string}>
 */
function routes(string $layout, int $count): array
{
    $routes = [];
    for ($i = 0; $i < intdiv($count, 2); $i++) {
        [$resource, $requirements] = match ($layout) {
            'separate', 'numeric' => ["/resource$i", []],
            'shared' => ["/api/resource$i", []],
            'tenant' => ["/api/{tenant}/resource$i", []],
            'locale' => ["/{lang:[a-z]{2}}/resource$i", ['lang' => '[a-z]{2}']],
            'optional-locale' => ["[/{lang:[a-z]{2}}]/resource$i", ['lang' => '[a-z]{2}']],
        };
        $id = $layout === 'numeric' ? '{id:\d+}' : '{id}';
        $routes[] = ['path' => $resource, 'requirements' => $requirements, 'name' => "resource$i"];
        $routes[] = [
            'path' => "$resource/$id",
            'requirements' => $requirements + ($layout === 'numeric' ? ['id' => '\d+'] : []),
            'name' => "item$i",
        ];
    }

    return $routes;
}

/**
 * All requests are prepared before timing; the expected outcome is checked for every library.
 *
 * @return list<array{string, string, mixed}> Method, URI and expected normalized result
 */
function requests(string $layout, int $count, string $profile, int $lookups): array
{
    $half = intdiv($count, 2);
    [$prefix, $prefixParams] = match ($layout) {
        'separate', 'numeric' => ['', []],
        'shared' => ['/api', []],
        'tenant' => ['/api/acme', ['tenant' => 'acme']],
        'locale', 'optional-locale' => ['/en', ['lang' => 'en']],
    };

    $requests = [];
    for ($n = 0; $n < $lookups; $n++) {
        $i = $profile === 'last' ? $half - 1 : ($profile === 'mixed' ? intdiv($n, 2) : $n) % $half;
        $withId = $profile !== 'static' && ($profile !== 'mixed' || $n % 2 === 1);
        $id = (string) (1000 + $n % 991);
        $uri = "$prefix/resource$i" . ($withId ? "/$id" : '');
        $hit = [($withId ? 'item' : 'resource') . $i, $prefixParams + ($withId ? ['id' => $id] : [])];
        $requests[] = match ($profile) {
            'miss' => ['GET', "$uri/missing", '404'],
            '405' => ['POST', $uri, '405'],
            'head' => ['HEAD', $uri, $hit],
            default => ['GET', $uri, $hit],
        };
    }

    return $requests;
}

/**
 * Registers the routes and returns a native lookup plus a normalizer used outside the timer.
 *
 * @param list<array{path: string, requirements: array<string, string>, name: string}> $routes
 * @return array{Closure(string, string): mixed, Closure(mixed): mixed}
 */
function library(string $library, array $routes): array
{
    return match ($library) {
        'solo' => solo($routes),
        'solo-cached' => soloCached($routes),
        'leaf' => leaf($routes),
        'symfony' => symfony($routes),
        'slim' => slim($routes),
    };
}

/** @return array{Closure(string, string): mixed, Closure(mixed): mixed} */
function solo(array $routes): array
{
    if (!method_exists(RouteCollector::class, 'build')) {
        return soloV3($routes);
    }

    $collector = new RouteCollector();
    foreach ($routes as $route) {
        $collector->get($route['path'], $route['name']);
    }
    $router = $collector->build();

    return [static fn(string $method, string $uri) => $router->resolve($method, $uri), soloResult(...)];
}

/**
 * Loads the router from a warm RouteCache file, as a request does in production. The file is
 * written on first use and dated back, so OPcache caches it despite file_update_protection.
 *
 * @return array{Closure(string, string): mixed, Closure(mixed): mixed}
 */
function soloCached(array $routes): array
{
    // Layout and size identify a table; keep the name cheap, it is part of the measured work
    $table = md5(($routes[0]['path'] ?? '') . '|' . count($routes));
    $file = sys_get_temp_dir() . '/solo-router-benchmark-' . getmypid() . "-$table.php";
    if (!is_file($file)) {
        register_shutdown_function(static fn() => @unlink($file));
        $collector = new RouteCollector();
        foreach ($routes as $route) {
            $collector->get($route['path'], $route['name']);
        }
        (new Solo\Router\RouteCache($file))->save($collector->build());
        touch($file, time() - 60);
    }

    $router = (new Solo\Router\RouteCache($file))->load(static function (): never {
        throw new RuntimeException('The benchmark must use the cache file');
    });

    return [static fn(string $method, string $uri) => $router->resolve($method, $uri), soloResult(...)];
}

function soloResult(mixed $result): mixed
{
    return match (true) {
        $result instanceof Matched => [$result->route->handler, $result->params],
        $result instanceof MethodNotAllowed => '405',
        default => '404',
    };
}

/**
 * Solo Router 3.x via SOLO_SRC (needs solophp/contracts): match(), then getAllowedMethods()
 * to tell 404 from 405, which resolve() does in one call.
 *
 * @return array{Closure(string, string): mixed, Closure(mixed): mixed}
 */
function soloV3(array $routes): array
{
    $router = new Solo\Router\Router();
    foreach ($routes as $route) {
        $router->addRoute('GET', $route['path'], $route['name']);
    }

    return [
        static fn(string $method, string $uri) => $router->match($method, $uri)
            ?: ($router->getAllowedMethods($uri) === [] ? '404' : '405'),
        static fn($result) => is_array($result) ? [$result['handler'], $result['params']] : $result,
    ];
}

/** Leaf matches the method cached from $_SERVER, so only GET profiles are measured. */
function leaf(array $routes): array
{
    Leaf\Router::reset();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    foreach ($routes as $route) {
        Leaf\Router::get($route['path'], ['name' => $route['name'], static function (): void {
        }]);
    }

    return [
        // Passing null keeps Leaf's indexed lookup; passing its route array bypasses that index.
        static fn(string $method, string $uri) => Leaf\Router::findRoute(null, $uri),
        static fn($result) => $result === [] ? '404' : [
            $result[0]['route']['name'],
            $result[0]['params'] === [] ? [] : ['id' => (string) array_values($result[0]['params'])[0]],
        ],
    ];
}

/** Symfony's compiled matcher, as used in production and by Laravel's route cache. */
function symfony(array $routes): array
{
    $collection = new SymfonyRoutes();
    foreach ($routes as $route) {
        // Symfony takes requirements separately and has no optional segments in brackets.
        $path = (string) preg_replace('/\{(\w+):[^\/]*}(?=\/|$)/', '{$1}', $route['path']);
        $collection->add($route['name'], new SymfonyRoute($path, [], $route['requirements'], methods: ['GET']));
    }
    $context = new RequestContext();
    $matcher = new CompiledUrlMatcher((new CompiledUrlMatcherDumper($collection))->getCompiledRoutes(), $context);

    return [
        static function (string $method, string $uri) use ($context, $matcher) {
            $context->setMethod($method);
            try {
                return $matcher->match($uri);
            } catch (MethodNotAllowedException) {
                return '405';
            } catch (ResourceNotFoundException) {
                return '404';
            }
        },
        static function ($result) {
            if (!is_array($result)) {
                return $result;
            }
            $name = $result['_route'];
            unset($result['_route']);
            return [$name, $result];
        },
    ];
}

/** Slim's dispatcher, which wraps FastRoute. */
function slim(array $routes): array
{
    $collector = new SlimRoutes(new HttpFactory(), new CallableResolver());
    $names = [];
    foreach ($routes as $route) {
        $names[$collector->map(['GET'], $route['path'], static function (): void {
        })->getIdentifier()] = $route['name'];
    }
    $dispatcher = new SlimDispatcher($collector);

    return [
        static fn(string $method, string $uri) => $dispatcher->dispatch($method, $uri),
        static fn(RoutingResults $result) => match ($result->getRouteStatus()) {
            RoutingResults::FOUND => [$names[$result->getRouteIdentifier()], $result->getRouteArguments()],
            RoutingResults::METHOD_NOT_ALLOWED => '405',
            default => '404',
        },
    ];
}

/** @param list<float> $samples */
function median(array $samples): float
{
    sort($samples);
    return $samples[intdiv(count($samples), 2)];
}

/**
 * @param array<string, string> $options Parsed long options
 * @return list<string>
 */
function listOption(array $options, string $name, array $default): array
{
    return isset($options[$name]) ? explode(',', $options[$name]) : $default;
}

function environment(): array
{
    return [
        'php' => PHP_VERSION,
        'os' => php_uname('s') . ' ' . php_uname('m'),
        'opcache' => (bool) ini_get('opcache.enable_cli') && extension_loaded('Zend OPcache'),
        'jit' => ini_get('opcache.jit') ?: 'off',
        'pcre' => PCRE_VERSION,
        'pcre_jit' => (bool) ini_get('pcre.jit'),
        'xdebug' => extension_loaded('xdebug'),
    ];
}
