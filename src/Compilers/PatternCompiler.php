<?php

declare(strict_types=1);

namespace Solo\Router\Compilers;

use InvalidArgumentException;

/**
 * Compiles a route path into an anchored regular expression in a single pass.
 *
 * Syntax: `{name}` and `{name:regex}` parameters, `[...]` optional segments (nestable),
 * `(...)` raw regex groups with an optional trailing quantifier. Everything else is literal.
 *
 * @internal
 */
final class PatternCompiler
{
    private const SPECIAL_CHARS = '{}[]()';
    private const DEFAULT_PARAM_PATTERN = '[^/]+';
    private const QUANTIFIER = '/\G(?:[?*+]|\{\d+(?:,\d*)?})[?+]?/';

    /**
     * @throws InvalidArgumentException If the path syntax is invalid
     */
    public function compile(string $path): string
    {
        $regex = '';
        $params = [];
        $optionalDepth = 0;
        $length = strlen($path);
        $i = 0;

        while ($i < $length) {
            $literalLength = strcspn($path, self::SPECIAL_CHARS, $i);
            if ($literalLength > 0) {
                $regex .= preg_quote(substr($path, $i, $literalLength), '#');
                $i += $literalLength;
                continue;
            }

            switch ($path[$i]) {
                case '{':
                    $end = $this->findClosing($path, $i, '{', '}');
                    $regex .= $this->compileParameter(substr($path, $i + 1, $end - $i - 1), $params, $path);
                    $i = $end + 1;
                    break;
                case '(':
                    $end = $this->findClosing($path, $i, '(', ')') + 1;
                    $end += $this->quantifierLength($path, $end);
                    $regex .= substr($path, $i, $end - $i);
                    $i = $end;
                    break;
                case '[':
                    $optionalDepth++;
                    $regex .= '(?:';
                    $i++;
                    break;
                case ']':
                    if ($optionalDepth === 0) {
                        throw new InvalidArgumentException(
                            "Unmatched closing bracket at position {$i} in: {$path}"
                        );
                    }
                    $optionalDepth--;
                    $regex .= ')?';
                    $i++;
                    break;
                default:
                    throw new InvalidArgumentException(
                        "Unmatched '{$path[$i]}' at position {$i} in: {$path}"
                    );
            }
        }

        if ($optionalDepth !== 0) {
            throw new InvalidArgumentException("Unmatched opening bracket(s) in: {$path}");
        }

        // D: "$" matches only at the very end, not before a trailing newline
        return "#^{$regex}$#D";
    }

    /**
     * @param array<string, true> $params Names already used in the path
     */
    private function compileParameter(string $definition, array &$params, string $path): string
    {
        $colon = strpos($definition, ':');
        $name = $colon === false ? $definition : substr($definition, 0, $colon);
        $pattern = $colon === false ? self::DEFAULT_PARAM_PATTERN : substr($definition, $colon + 1);

        if (!preg_match('/^[A-Za-z_]\w*$/', $name)) {
            throw new InvalidArgumentException("Invalid parameter name '{$name}' in: {$path}");
        }
        if (isset($params[$name])) {
            throw new InvalidArgumentException("Duplicate parameter '{$name}' in: {$path}");
        }
        $params[$name] = true;

        return "(?P<{$name}>{$pattern})";
    }

    /**
     * Finds the delimiter closing the one at $start, skipping escapes and character classes.
     */
    private function findClosing(string $path, int $start, string $open, string $close): int
    {
        // Fast path: nothing to skip or nest before the closing delimiter, e.g. "{id}"
        $end = $start + 1 + strcspn($path, $open . $close . '[\\', $start + 1);
        if (($path[$end] ?? '') === $close) {
            return $end;
        }

        $depth = 0;
        $length = strlen($path);

        for ($i = $start; $i < $length; $i++) {
            $char = $path[$i];
            if ($char === '\\') {
                $i++;
            } elseif ($char === '[') {
                $i = $this->findCharacterClassEnd($path, $i);
            } elseif ($char === $open) {
                $depth++;
            } elseif ($char === $close && --$depth === 0) {
                return $i;
            }
        }

        throw new InvalidArgumentException("Unmatched '{$open}' at position {$start} in: {$path}");
    }

    private function findCharacterClassEnd(string $path, int $start): int
    {
        $length = strlen($path);
        $i = $start + 1;
        if ($i < $length && $path[$i] === '^') {
            $i++;
        }
        if ($i < $length && $path[$i] === ']') {
            $i++; // A leading ']' is literal
        }

        for (; $i < $length; $i++) {
            if ($path[$i] === '\\') {
                $i++;
            } elseif ($path[$i] === ']') {
                return $i;
            }
        }

        throw new InvalidArgumentException("Unterminated character class at position {$start} in: {$path}");
    }

    private function quantifierLength(string $path, int $offset): int
    {
        return preg_match(self::QUANTIFIER, $path, $m, 0, $offset) ? strlen($m[0]) : 0;
    }
}
