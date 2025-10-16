<?php

declare(strict_types=1);

namespace Solo\Router\Compilers;

use InvalidArgumentException;

final class PatternCompiler
{
    /** @var array<string, array{name: string, pattern: string}> */
    private array $parameters = [];

    public function compile(string $path): string
    {
        $this->parameters = [];

        // Optimized compilation process
        $pattern = $this->extractParameters($path);
        $pattern = $this->processOptionalSegments($pattern);
        $pattern = $this->restoreParameters($pattern);
        $pattern = $this->escapePattern($pattern);

        return "#^{$pattern}$#";
    }

    private function extractParameters(string $path): string
    {
        return preg_replace_callback(
            '/{(\w+)(?::([^{}]*(?:{[^}]*}[^{}]*)*))?}/',
            function ($m) {
                $index = count($this->parameters);
                $placeholder = "__P{$index}__";
                $this->parameters[$placeholder] = [
                    'name' => $m[1],
                    'pattern' => $m[2] ?? '[^\/]+'
                ];
                return $placeholder;
            },
            $path
        ) ?? $path;
    }

    private function processOptionalSegments(string $pattern): string
    {
        $this->validateBrackets($pattern);

        while (preg_match('/\[([^\[\]]*)]/', $pattern, $m, PREG_OFFSET_CAPTURE)) {
            $content = $m[1][0];
            $offset = (int)$m[0][1];
            $length = strlen($m[0][0]);

            $replacement = "(?:{$content})?";
            $pattern = substr_replace($pattern, $replacement, $offset, $length);
        }

        return $pattern;
    }

    private function restoreParameters(string $pattern): string
    {
        foreach ($this->parameters as $placeholder => $param) {
            $regex = "(?P<{$param['name']}>{$param['pattern']})";
            $pattern = str_replace($placeholder, $regex, $pattern);
        }
        return $pattern;
    }

    private function escapePattern(string $pattern): string
    {
        $constructs = [];

        // First, protect all regex constructs including nested ones
        $depth = 0;
        $start = -1;
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            if (substr($pattern, $i, 2) === '(?') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                $i++; // Skip the next character
            } elseif ($pattern[$i] === '(' && $i > 0 && $pattern[$i - 1] !== '\\') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($pattern[$i] === ')' && $i > 0 && $pattern[$i - 1] !== '\\') {
                $depth--;
                if ($depth === 0 && $start >= 0) {
                    // Check if there's a quantifier after the closing parenthesis
                    $end = $i + 1;
                    if ($end < $len && in_array($pattern[$end], ['?', '*', '+'])) {
                        $end++;
                    }

                    $construct = substr($pattern, $start, $end - $start);
                    $id = '__REG_' . count($constructs) . '__';
                    $constructs[$id] = $construct;
                    $pattern = substr_replace($pattern, $id, $start, $end - $start);
                    $len = strlen($pattern);
                    $i = $start + strlen($id) - 1;
                }
            }
        }

        $escaped = preg_quote($pattern, '#');

        foreach ($constructs as $id => $construct) {
            $escaped = str_replace(preg_quote($id, '#'), $construct, $escaped);
        }

        return $escaped;
    }

    private function validateBrackets(string $pattern): void
    {
        $depth = 0;
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            if ($pattern[$i] === '[') {
                $depth++;
            } elseif ($pattern[$i] === ']') {
                $depth--;
                if ($depth < 0) {
                    throw new InvalidArgumentException(
                        "Unmatched closing bracket at position {$i} in: {$pattern}"
                    );
                }
            }
        }

        if ($depth !== 0) {
            throw new InvalidArgumentException(
                "Unmatched opening bracket(s) in: {$pattern}"
            );
        }
    }
}
