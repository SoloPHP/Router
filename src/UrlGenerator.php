<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;
use Solo\Router\Compilers\RegexLexer;

/**
 * Generates the paths of named routes: the reverse of Router::resolve().
 *
 * It fills in the path of the route, which every router keeps, including one restored from RouteCache.
 * The result is a path: a base path or a host is up to the application.
 */
final readonly class UrlGenerator
{
    private RegexLexer $lexer;

    public function __construct(private Router $router)
    {
        $this->lexer = new RegexLexer();
    }

    /**
     * Returns the path of a named route with its parameters filled in, followed by a query string
     * if $query is not empty.
     *
     * Values are encoded with rawurlencode(), and each must then match the pattern of its parameter;
     * a "/" stays as it is where the pattern accepts it, as in {path:.+}. An optional segment is
     * generated only if a parameter in it has a value. A path left empty, as by "[/]", becomes "/".
     *
     * @param array<string, string|int|null> $params Values by parameter name; null counts as no value
     * @param array<mixed> $query Encoded by http_build_query() as RFC 3986 requires
     * @throws InvalidArgumentException If no route has the name, a parameter is missing, unknown or does
     *                                  not match its pattern, or the path has a raw regex group
     */
    public function generate(string $name, array $params = [], array $query = []): string
    {
        $route = $this->router->getRouteByName($name)
            ?? throw new InvalidArgumentException("Route with name '$name' does not exist");

        $i = 0;
        $known = [];
        try {
            $path = $this->fill($route->path, $i, $params, $known, false);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Cannot generate a URL for route '$name': {$e->getMessage()}", 0, $e);
        }
        $unknown = array_key_first(array_diff_key($params, $known));
        if ($unknown !== null) {
            throw new InvalidArgumentException(
                "Cannot generate a URL for route '$name': it has no parameter '$unknown'; pass query values in \$query"
            );
        }

        $query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return ($path === '' ? '/' : $path) . ($query === '' ? '' : "?$query");
    }

    /**
     * Fills in the path from $i to its end, or to the "]" that closes the optional segment at $i.
     * An optional segment in which no parameter has a value is left out: the result is "".
     *
     * @param array<string, string|int|null> $params
     * @param array<string, true> $known Receives the name of every parameter in the path
     * @throws InvalidArgumentException If a parameter is missing or does not match its pattern
     */
    private function fill(string $path, int &$i, array $params, array &$known, bool $optional): string
    {
        $url = '';
        $filled = false;
        $missing = null;
        $length = strlen($path);

        while ($i < $length) {
            $literal = strcspn($path, '{[](', $i);
            $url .= substr($path, $i, $literal);
            $i += $literal;
            if ($i === $length) {
                break;
            }

            switch ($path[$i]) {
                case '{':
                    // Read as PatternCompiler reads it; build() has validated the path
                    $open = $i;
                    $end = $i + 1 + strcspn($path, ':}', $i + 1);
                    $param = trim(substr($path, $i + 1, $end - $i - 1));
                    $i = $end + 1;
                    $regex = $path[$end] === ':' ? $this->lexer->read($path, $i, $open)[0] : '[^/]+';
                    $known[$param] = true;
                    if (isset($params[$param])) {
                        $url .= $this->encode($param, $params[$param], $regex);
                        $filled = true;
                    } else {
                        $missing ??= $param;
                    }
                    break;
                case '[':
                    $i++;
                    $segment = $this->fill($path, $i, $params, $known, true);
                    $url .= $segment;
                    $filled = $filled || $segment !== '';
                    break;
                case ']':
                    $i++;
                    break 2;
                default:
                    throw new InvalidArgumentException('its path has a raw regex group, which no parameter can fill');
            }
        }

        if ($optional && !$filled) {
            return '';
        }
        if ($missing !== null) {
            throw new InvalidArgumentException("parameter '$missing' is missing");
        }

        return $url;
    }

    /**
     * @throws InvalidArgumentException If the encoded value does not match the pattern
     */
    private function encode(string $param, string|int $value, string $regex): string
    {
        $encoded = rawurlencode((string) $value);
        $pattern = '#^(?:' . $regex . ')$#D';
        // A value keeps "/" where its pattern accepts it, e.g. {path:.+}
        foreach ([str_replace('%2F', '/', $encoded), $encoded] as $candidate) {
            if (preg_match($pattern, $candidate) === 1) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException("parameter '$param' must match '$regex', got '$value'");
    }
}
