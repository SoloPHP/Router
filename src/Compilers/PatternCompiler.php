<?php

declare(strict_types=1);

namespace Solo\Router\Compilers;

use InvalidArgumentException;

/**
 * Compiles a route path into a key in a single pass.
 *
 * A key keeps literal bytes as they are and replaces every group with a 4-byte placeholder:
 * NUL, the group kind, and two id bytes without NUL. Equal groups share a placeholder, so
 * equal keys mean equal patterns, and the keys of different routes compare byte by byte.
 *
 * Syntax: `{name}` and `{name:regex}` parameters, `[...]` optional segments (nestable),
 * `(...)` raw regex groups with an optional trailing quantifier. Everything else is literal.
 * RegexLexer reads and validates the regexes; a raw group is matched inside a group of its own.
 * Exceptions describe the problem in the path; RouteCompiler adds the route to the message.
 *
 * @internal
 */
final class PatternCompiler
{
    /** First byte of every placeholder; RouteCompiler rejects route paths that contain NUL. */
    public const string MARKER = "\0";

    /** Kind of a group that consumes exactly one whole segment: routes may share it. */
    private const string SEGMENT = "\1";

    /** Kind of a group whose span depends on what follows it: routes never share it. */
    private const string COMPLEX = "\2";

    /** Bytes that start or end a group; a path without them is static. */
    public const string SPECIAL_CHARS = '{}[]()';

    private const string DEFAULT_PARAM_PATTERN = '[^/]+';

    /** Conservative whitelist of regexes that cannot match "/". */
    private const string SEGMENT_SAFE = '#^(?:[\w~-]|\\\\[dws.~-]|\[-?(?:[a-z]-[a-z]|[A-Z]-[A-Z]|[0-9]-[0-9]|[\w.~]'
        . '|\\\\[dws.~-])+-?]|[?*+|)]|\((?!\?)|\(\?:|\{\d+(?:,\d*)?})*$#D';

    private readonly RegexLexer $lexer;

    /** @var array<string, string> Regex source by placeholder */
    private array $groups = [];

    /** @var array<string, string> Placeholder by kind and regex source */
    private array $placeholders = [];

    /** @var array<string, bool> */
    private array $segments = [];

    public function __construct()
    {
        $this->lexer = new RegexLexer();
    }

    /**
     * @return array{string, array<int, string>} The key, and parameter names by group number
     * @throws InvalidArgumentException If the path syntax or a regex is invalid
     */
    public function compile(string $path): array
    {
        $i = $group = 0;
        $params = [];
        $key = $this->parse($path, $i, $group, $params, false);

        return [$key, array_flip($params)];
    }

    /**
     * Returns the regex source for bytes [$from, $to) of a key. Both offsets lie between tokens.
     */
    public function regex(string $key, int $from, int $to): string
    {
        $regex = '';
        while ($from < $to) {
            $at = strpos($key, self::MARKER, $from);
            if ($at === false || $at >= $to) {
                return $regex . preg_quote(substr($key, $from, $to - $from), '#');
            }
            $regex .= preg_quote(substr($key, $from, $at - $from), '#') . $this->groups[substr($key, $at, 4)];
            $from = $at + 4;
        }

        return $regex;
    }

    /**
     * Returns the end of the longest common prefix of keys $a and $b, between $from and $length,
     * that routes may share: it ends between tokens and contains no complex group.
     */
    public static function commonPrefix(string $a, string $b, int $from, int $length): int
    {
        // Equal bytes XOR to NUL, so the run of NUL bytes is the common prefix.
        $common = $from + strspn($a ^ $b, "\0", $from, $length - $from);

        $complex = strpos($a, self::MARKER . self::COMPLEX, $from);
        if ($complex !== false && $complex < $common) {
            return $complex;
        }
        // Back off to the start of a placeholder that the prefix would split.
        for ($i = $common - 1; $i >= $from && $i > $common - 4; $i--) {
            if ($a[$i] === self::MARKER) {
                return $i;
            }
        }

        return $common;
    }

    /**
     * @param array<string, int> $params Group number by parameter name
     */
    private function parse(string $path, int &$i, int &$group, array &$params, bool $optional): string
    {
        $key = '';
        $length = strlen($path);

        while ($i < $length) {
            $literalLength = strcspn($path, self::SPECIAL_CHARS, $i);
            if ($literalLength > 0) {
                $key .= substr($path, $i, $literalLength);
                $i += $literalLength;
                continue;
            }

            switch ($path[$i]) {
                case '{':
                    $open = $i;
                    $name = $this->parameterName($path, $i, $params);
                    $params[$name] = ++$group;
                    $pattern = self::DEFAULT_PARAM_PATTERN;
                    if ($path[$i - 1] === ':') {
                        [$pattern, $captures] = $this->lexer->read($path, $i, $open);
                        if ($pattern === '') {
                            throw new InvalidArgumentException("has an empty pattern for parameter '{$name}'");
                        }
                        $group += $captures;
                    }
                    $key .= $this->placeholder("({$pattern})", $this->kind($pattern, $path, $i));
                    break;
                case '(':
                    $open = $i;
                    [$raw, $captures] = $this->lexer->read($path, $i, $open);
                    $group += $captures;
                    // A quantifier never ends with ")", and routes never share a quantified group
                    $kind = $raw[-1] === ')' ? $this->kind(substr($raw, 1, -1), $path, $i) : self::COMPLEX;
                    // A group of its own confines inline options to the raw group, even ones the lexer missed
                    $key .= $this->placeholder("(?:{$raw})", $kind);
                    break;
                case '[':
                    $i++;
                    $inner = $this->parse($path, $i, $group, $params, true);
                    $key .= $this->placeholder('(?:' . $this->regex($inner, 0, strlen($inner)) . ')?', self::COMPLEX);
                    break;
                case ']':
                    if (!$optional) {
                        throw new InvalidArgumentException("has an unmatched closing bracket at position {$i}");
                    }
                    $i++;
                    return $key;
                default:
                    throw new InvalidArgumentException("has an unmatched '{$path[$i]}' at position {$i}");
            }
        }

        if ($optional) {
            throw new InvalidArgumentException('has an unmatched opening bracket');
        }

        return $key;
    }

    private function placeholder(string $regex, string $kind): string
    {
        $existing = $this->placeholders[$kind . $regex] ?? null;
        if ($existing !== null) {
            return $existing;
        }

        $id = count($this->groups);
        if ($id >= 255 * 255) {
            throw new InvalidArgumentException('exceeds the limit of 65025 distinct regex groups in one router');
        }
        $placeholder = self::MARKER . $kind . pack('C2', intdiv($id, 255) + 1, $id % 255 + 1);
        $this->groups[$placeholder] = $regex;

        return $this->placeholders[$kind . $regex] = $placeholder;
    }

    /**
     * Reads the name of the parameter at $i and moves $i past the ":" or "}" after it.
     * Surrounding whitespace is ignored: a raw space never appears in a request path.
     *
     * @param array<string, int> $params Names already used in the path
     */
    private function parameterName(string $path, int &$i, array $params): string
    {
        $end = $i + 1 + strcspn($path, ':}', $i + 1);
        if ($end >= strlen($path)) {
            throw new InvalidArgumentException("has an unmatched '{' at position {$i}");
        }
        $name = trim(substr($path, $i + 1, $end - $i - 1));
        $i = $end + 1;

        if (!preg_match('/^[A-Za-z_]\w*$/D', $name)) {
            throw new InvalidArgumentException("has an invalid parameter name '{$name}'");
        }
        if (isset($params[$name])) {
            throw new InvalidArgumentException("has a duplicate parameter '{$name}'");
        }

        return $name;
    }

    /**
     * A group consumes exactly one whole segment if its regex cannot match "/" and a separator
     * or the end of the path follows it at $next.
     */
    private function kind(string $pattern, string $path, int $next): string
    {
        return ($path[$next] ?? '/') === '/' && $this->isSegment($pattern) ? self::SEGMENT : self::COMPLEX;
    }

    private function isSegment(string $pattern): bool
    {
        return $this->segments[$pattern]
            ??= $pattern === self::DEFAULT_PARAM_PATTERN || preg_match(self::SEGMENT_SAFE, $pattern) === 1;
    }
}
