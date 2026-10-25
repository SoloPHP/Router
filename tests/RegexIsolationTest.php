<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use ErrorException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Router\Enums\HttpMethod;
use Solo\Router\Results\Matched;
use Solo\Router\Results\MethodNotAllowed;
use Solo\Router\Results\NotFound;
use Solo\Router\RouteCollector;
use Solo\Router\Router;

/**
 * The routes of a method share one combined pattern, so an accepted regex must act only on its
 * own route. Random regexes mix supported syntax with text that PCRE reads differently from a
 * naive scanner (quotes, classes, POSIX names, comments, callouts, \c) and with constructs that
 * act on other routes (verbs, recursion, inline options). Whenever build() accepts one, the
 * router must resolve every URI exactly like its routes tried one by one.
 */
final class RegexIsolationTest extends TestCase
{
    private const array TOKENS = [
        '(', ')', '(', ')', 'a', 'b', 'a', 'x', '0', '/', '-', ':', '=', '!', '<', '>', "'", '"', ' ', '#', '|', '^',
        '$', '.', '?', '*', '+', '{', '}', '{2}', '[', ']', '[^', '[]', '[^]', '\]', '\[', '\(', '\)', '\\', '\#',
        '\Q', '\E', '\Q\E', '\E\Q', '[\Q', '\d', '\w', '\b', '\K', '\0', '\x', '\x{41}', '\x{', '\o{101}', '\p{L}',
        '\p{', '\pL', '\N', '\N{', '\c', '\1', '\g1', '\g{1}', '\g{-1}', '\g<1>', '\k<n>', '[:alpha:]', '[[:alpha:]]',
        '[.a.]', '[=a=]', '(?:', '(?=', '(?!', '(?<=', '(?<!', '(?|', '(?>', '(?i:', '(?i)', '(?x)', '(?n)', '(?-i)',
        '(?^)', '(?)', '(?R)', '(?1)', '(?-1)', '(?&n)', '(?(1)', '(?<n>', '(?P<n>', "(?'n'", '(?P=n)', '(?P>n)',
        '(?#', '(?C1)', '(?C"', '(*COMMIT)', '(*ACCEPT)', '(*:9)', '(*F)', '(*SKIP)', '(*THEN)', '(*UTF)',
    ];

    /** Text that PCRE reads literally around "%s", but a scanner may count as parentheses */
    private const array HIDERS = ['\Q%s\E', '[[:alpha:]%s]', '(?C"%s")', '[\Q]%s\E]', '[.%s.]', '[=%s=]', '(?#%s', '[%s]'];

    /** Escapes that a scanner may read as one character too short */
    private const array CHAR_HIDERS = ['\c%s', '\%s'];

    /** Closes the enclosing group and opens a new one, by depth */
    private const array BREAKOUTS = [
        1 => ['a)|(b', 'a)(?i)(b', 'a)(*COMMIT)(b', ')(', 'a)(?x)(b'],
        2 => ['a))((b', 'a))(*ACCEPT)((b', '))((', 'a))|((b'],
    ];

    /** Text around "%s" that a scanner may misread as quoted or as part of a class */
    private const array DISGUISES = [
        '\Q[\E%s]', '\Q\E%s', '[\Q]\E%s]', '[[:alpha:]%s]', '[[:a]%s:]]', '[x[:a]%s:]]', '[[=a]%s=]]', '[[:a%s:]]',
        '[[:a[:b%s:]]', '[[.a.]%s]', '[[:a\]%s:]]', '[\\\\[:a%s:]]', '[a[:%s]', '[^[:^a:]%s]', '(?#%s)', '\c%s',
        '[\]%s]', '[]%s]', '%s',
    ];

    /** Constructs that would act on other routes in a combined pattern */
    private const array ACTIONS = [
        '(*COMMIT)', '(*ACCEPT)', '(*:0)', '(*SKIP)', '(*PRUNE)', '(*THEN)', '(?R)', '(?1)', '(?-1)', '\g<1>', '(?i)',
        '(?x)',
    ];

    /** Registered before the random route; "/x/{n}" and "/x{rest}" overlap it */
    private const array ROUTES_BEFORE = [
        ['GET', '/y/{id}'], ['GET', '/y/{id}/edit'], ['POST', '/p/{id}'], ['GET', '/z/{slug:[a-z]+}'],
        ['GET', '/x/{n:\d+}'],
    ];

    private const array ROUTES_AFTER = [
        ['GET', '/y/{id}/{tab:\d+}'], ['GET', '/w[/{page}]'], ['GET', '/{lang:en|ru}/v'], ['GET', '/r/(a|b)c'],
        ['HEAD', '/h/{id}'], ['POST', '/y/{id}'], ['GET', '/x{rest:.*}'], ['POST', '/{any:.*}'],
    ];

    private const array URIS = [
        '', '/', '/y/1', '/Y/1', '/y/1/edit', '/y/1/22', '/p/7', '/z/abc', '/w', '/w/3', '/en/v', '/de/v', '/r/ac',
        '/h/5', '/x/12',
    ];

    /** @var array<string, Router> Single-route routers by method and path */
    private array $singles = [];

    #[DataProvider('misreadRegexes')]
    public function testRegexThatAScannerMisreadsIsRejected(string $path): void
    {
        $routes = new RouteCollector();
        $routes->get($path, 'misread');
        $routes->get('/y/{id}', 'neighbour');

        $this->expectException(InvalidArgumentException::class);
        $routes->build();
    }

    public static function misreadRegexes(): iterable
    {
        yield 'quoted parentheses' => ['/x(\Q((\Ea))((b\Q))\E)'];
        yield 'verb after quoted text in a comment' => ['/x((?#\Q)(*COMMIT)\E)'];
        yield 'control escape of a parenthesis' => ['/x(\c(\c(a))((b\c)\c))'];
        yield 'parentheses after a POSIX class' => ['/x([[:alpha:]((]a))((b[[:alpha:]))])'];
        yield 'parentheses in a callout string' => ['/x((?C"((")a))((b(?C"))"))'];
        yield 'parentheses in a comment' => ['/x((?#((a))((b)'];
        yield 'alternatives escaping a parameter through a quoted class' => ['/x/{v:[\Q](\E]a)|(b[\Q])\E]}'];
        yield 'verb after "[" that is not a POSIX class' => ['/x([[:a](*COMMIT):]])'];
    }

    public function testAcceptedRegexesActOnlyOnTheirOwnRoute(): void
    {
        // A warning, such as a missing route mark, must fail the test
        set_error_handler(static function (int $level, string $message): bool {
            if ((error_reporting() & $level) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $level);
        });

        try {
            mt_srand(20261025);
            $accepted = 0;
            for ($case = 0; $case < 3000; $case++) {
                $regex = $this->randomRegex();
                foreach (["/x($regex)", "/x/{v:$regex}", "/x[($regex)]", "/x/{v:($regex)}"] as $path) {
                    $routes = [...self::ROUTES_BEFORE, ['GET', $path], ...self::ROUTES_AFTER];
                    $builder = new RouteCollector();
                    foreach ($routes as $id => [$method, $routePath]) {
                        $builder->addRoute($method, $routePath, $id);
                    }
                    try {
                        $router = $builder->build();
                    } catch (InvalidArgumentException) {
                        continue;
                    }

                    $accepted++;
                    $uris = [...self::URIS, ...$this->urisNear($regex)];
                    $this->assertSame($this->expected($routes, $uris), $this->actual($router, $uris), "Route $path");
                }
            }
        } finally {
            restore_error_handler();
        }

        // Most random regexes are rejected; enough must be accepted for the test to mean something
        $this->assertGreaterThan(500, $accepted);
    }

    /**
     * Outcomes of GET, POST and HEAD and the allowed methods, by URI, with routes tried one by one.
     *
     * @param list<array{string, string}> $routes
     * @param list<string> $uris
     * @return array<string, list<mixed>>
     */
    private function expected(array $routes, array $uris): array
    {
        $expected = [];
        foreach ($uris as $uri) {
            $params = [];
            foreach ($routes as $id => [$method, $path]) {
                $result = $this->single($method, $path)->resolve($method, $uri);
                if ($result instanceof Matched) {
                    $params[$id] = $result->params;
                }
            }

            $methods = [];
            foreach (array_keys($params) as $id) {
                $methods[$routes[$id][0]] = true;
            }
            if (isset($methods['GET'])) {
                $methods['HEAD'] = true;
            }
            $allowed = [];
            foreach (HttpMethod::cases() as $case) {
                if (isset($methods[$case->value])) {
                    $allowed[] = $case->value;
                }
            }

            foreach (['GET', 'POST', 'HEAD'] as $method) {
                $expected[$uri][] = $this->firstMatch($routes, $params, $method, $uri)
                    ?? ($allowed === [] ? ['not found'] : ['not allowed', $allowed]);
            }
            $expected[$uri][] = $allowed;
        }

        return $expected;
    }

    /**
     * Static routes precede dynamic routes of the same method; HEAD falls back to GET.
     *
     * @param list<array{string, string}> $routes
     * @param array<int, array<string, string>> $params Parameters by the ID of each matching route
     * @return list<mixed>|null
     */
    private function firstMatch(array $routes, array $params, string $method, string $uri): ?array
    {
        foreach ($method === 'HEAD' ? ['HEAD', 'GET'] : [$method] as $candidate) {
            foreach ($routes as $id => [$routeMethod, $path]) {
                if ($routeMethod === $candidate && $path === $uri && strpbrk($path, '{}[]()') === false) {
                    return ['matched', $id, []];
                }
            }
            foreach ($routes as $id => [$routeMethod, $path]) {
                if ($routeMethod === $candidate && isset($params[$id]) && strpbrk($path, '{}[]()') !== false) {
                    return ['matched', $id, $params[$id]];
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $uris
     * @return array<string, list<mixed>>
     */
    private function actual(Router $router, array $uris): array
    {
        $actual = [];
        foreach ($uris as $uri) {
            foreach (['GET', 'POST', 'HEAD'] as $method) {
                $result = $router->resolve($method, $uri);
                $actual[$uri][] = match (true) {
                    $result instanceof Matched => ['matched', $result->route->handler, $result->params],
                    $result instanceof MethodNotAllowed => ['not allowed', $result->allowedMethods],
                    $result instanceof NotFound => ['not found'],
                };
            }
            $actual[$uri][] = $router->getAllowedMethods($uri);
        }

        return $actual;
    }

    private function single(string $method, string $path): Router
    {
        if (!isset($this->singles["$method $path"])) {
            $builder = new RouteCollector();
            $builder->addRoute($method, $path, 'single');
            $this->singles["$method $path"] = $builder->build();
        }

        return $this->singles["$method $path"];
    }

    private function randomRegex(): string
    {
        switch (mt_rand(0, 2)) {
            case 0:
                $depth = mt_rand(1, 2);
                return $this->tokens(2) . $this->hidden(str_repeat('(', $depth)) . $this->tokens(1)
                    . self::pick(self::BREAKOUTS[$depth]) . $this->tokens(1) . $this->hidden(str_repeat(')', $depth))
                    . $this->tokens(2);
            case 1:
                $action = sprintf(self::pick(self::DISGUISES), self::pick(self::ACTIONS));
                return $this->tokens(1) . $action . $this->tokens(1);
            default:
                return $this->tokens(9) . self::pick(self::TOKENS);
        }
    }

    private function hidden(string $parentheses): string
    {
        if (mt_rand(0, 4) === 0) {
            return str_repeat(sprintf(self::pick(self::CHAR_HIDERS), $parentheses[0]), strlen($parentheses));
        }
        $hider = self::pick(self::HIDERS);
        // A comment ends at the first ")"
        return sprintf($hider === '(?#%s' && $parentheses[0] === ')' ? '\Q%s\E' : $hider, $parentheses);
    }

    private function tokens(int $max): string
    {
        $tokens = '';
        for ($n = mt_rand(0, $max); $n > 0; $n--) {
            $tokens .= self::pick(self::TOKENS);
        }

        return $tokens;
    }

    /**
     * URIs under "/x" made of the regex's characters, which reach constructs inside it.
     *
     * @return list<string>
     */
    private function urisNear(string $regex): array
    {
        $alphabet = [...str_split($regex), 'a', 'b', '/', '1', '[', ']', '(', ')'];
        $uris = [];
        for ($n = 0; $n < 10; $n++) {
            $uri = '/x';
            for ($length = mt_rand(0, 5); $length > 0; $length--) {
                $uri .= self::pick($alphabet);
            }
            $uris[] = $uri;
        }

        return $uris;
    }

    /**
     * @template T
     * @param array<int, T> $items
     * @return T
     */
    private static function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }
}
