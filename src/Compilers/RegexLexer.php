<?php

declare(strict_types=1);

namespace Solo\Router\Compilers;

use InvalidArgumentException;

/**
 * Reads the regex of a `{name:regex}` parameter or a raw `(...)` group in a route path.
 *
 * The dynamic routes of a method share one combined pattern, so each regex must be a closed
 * unit: its groups, character classes and quoted text end inside it, and it uses nothing that
 * acts on other routes. The lexer accepts only a subset of PCRE syntax that it reads exactly
 * as PCRE does, and rejects everything else. captures() then confirms with PCRE itself that
 * the regex is closed, so read() returns only regexes that passed both checks.
 *
 * Exceptions describe the problem in the path; RouteCompiler adds the route to the message.
 *
 * @internal
 */
final class RegexLexer
{
    /** Characters the lexer reads outside a class; all others are literal to it. */
    private const string SPECIAL = '\\[(){}#';

    /** Inline options that change neither how PCRE reads a regex nor anything outside a group. */
    private const string OPTIONS = 'imnsU^-';

    /** Optional quantifier after a raw group. */
    private const string QUANTIFIER = '/\G(?:[?*+]|\{\d+(?:,\d*)?})[?+]?/';

    /** @var array<string, int> Capture groups by validated regex, prefixed with "{" or "(" */
    private array $captures = [];

    /**
     * Reads a parameter's regex up to its closing "}" and trims it, or a raw group through its
     * closing ")" and quantifier. "#" is escaped for the pattern delimiter.
     *
     * @param int $i Offset after the parameter's ":", or of the raw group's "("; moved past the regex
     * @param int $open Offset of the parameter's "{" or of the raw group's "("
     * @return array{string, int} The regex and the number of its capture groups
     * @throws InvalidArgumentException If the regex does not end, uses unsupported syntax or does not compile
     */
    public function read(string $path, int &$i, int $open): array
    {
        $parameter = $path[$open] === '{';
        $length = strlen($path);
        $regex = '';
        $depth = $braces = 0;
        if ($parameter) {
            $i += strspn($path, " \t\n\r\v", $i);
        }

        while (true) {
            $run = strcspn($path, self::SPECIAL, $i);
            $plain = substr($path, $i, $run);
            $i += $run;
            if ($i >= $length) {
                throw new InvalidArgumentException("has an unmatched '{$path[$open]}' at position {$open}");
            }

            $char = $path[$i];
            if ($char === '}' && $parameter && $braces === 0) {
                if ($depth > 0) {
                    throw $this->unsupported($i, 'groups must open and close inside the parameter');
                }
                $i++;
                $regex .= rtrim($plain);

                return [$regex, $this->captures($regex, true)];
            }
            $regex .= $plain;

            switch ($char) {
                case '\\':
                    $regex .= $this->escape($path, $i);
                    break;
                case '[':
                    $regex .= $this->characterClass($path, $i);
                    break;
                case '(':
                    $opener = $this->groupOpener($path, $i);
                    $opensGroup = $path[$i + $opener - 1] !== ')';
                    if (!$opensGroup && !$parameter && $depth === 0) {
                        throw $this->unsupported(
                            $i,
                            'inline options must apply to a group, e.g. (?i:...), or they would change how later '
                            . 'routes match'
                        );
                    }
                    $regex .= substr($path, $i, $opener);
                    $i += $opener;
                    $depth += (int) $opensGroup;
                    break;
                case ')':
                    if ($depth === 0) {
                        throw $this->unsupported($i, 'groups must open and close inside the parameter');
                    }
                    $regex .= ')';
                    $i++;
                    if (--$depth === 0 && !$parameter) {
                        $quantifier = preg_match(self::QUANTIFIER, $path, $match, 0, $i) === 1 ? $match[0] : '';
                        $i += strlen($quantifier);
                        $regex .= $quantifier;

                        return [$regex, $this->captures($regex, false)];
                    }
                    break;
                case '{':
                case '}':
                    // A parameter ends at a "}" that closes no "{" of its regex
                    $braces += $char === '{' ? 1 : -1;
                    $regex .= $char;
                    $i++;
                    break;
                default:
                    $regex .= '\#';
                    $i++;
            }
        }
    }

    /**
     * Validates a regex for read() and counts its capture groups. Compiling it alone and then
     * inside a group proves that PCRE reads it as a closed unit: no ")" closes a group opened
     * before it, and no class or quoted text continues past it. A raw group must also have no
     * top-level alternatives: DEFINE accepts only one, so PCRE agrees on where the group ends.
     *
     * @param bool $parameter Whether the regex belongs to a parameter, which is matched inside a group
     * @throws InvalidArgumentException If PCRE rejects the regex
     */
    private function captures(string $regex, bool $parameter): int
    {
        $key = ($parameter ? '{' : '(') . $regex;
        if (!isset($this->captures[$key])) {
            $unit = $parameter ? "(?:{$regex})?|" : "(?(DEFINE){$regex})";
            if (
                @preg_match("#{$regex}#", '') === false
                || @preg_match("#{$unit}#", '', $groups, PREG_UNMATCHED_AS_NULL) !== 1
            ) {
                throw new InvalidArgumentException("compiles to an invalid regular expression: $regex");
            }
            $this->captures[$key] = count($groups) - 1;
        }

        return $this->captures[$key];
    }

    /**
     * Reads an escape sequence. Each one spans at most the characters PCRE reads as part of it.
     *
     * Groups are numbered from the start of the route, so an absolute backreference would
     * refer to another group than it does in the regex alone; relative ones are allowed.
     */
    private function escape(string $path, int &$i, bool $inClass = false): string
    {
        $start = $i;
        $next = $path[$i + 1] ?? '';
        $argument = $path[$i + 2] ?? '';
        if (!$inClass && ctype_digit($next) && $next !== '0') {
            throw $this->unsupported($i, 'absolute backreferences are not allowed; use \g{-1}');
        }
        switch ($next) {
            case 'Q':
                $end = strpos($path, '\E', $i + 2);
                if ($end === false) {
                    throw new InvalidArgumentException("has an unterminated \\Q at position {$i}");
                }
                $i = $end + 2;
                // Quoted text is literal, so a "#" in it leaves the quote to be escaped
                return '\Q' . str_replace('#', '\E\#\Q', substr($path, $start + 2, $end - $start - 2)) . '\E';
            case 'c':
                throw $this->unsupported($i, 'control character escapes (\c) are not allowed');
            case 'k':
                throw $this->unsupported($i, 'named references are not allowed');
            case 'N':
                if ($argument === '{') {
                    throw $this->unsupported($i, '\N{...} is not allowed');
                }
                $length = 2;
                break;
            case 'g':
                if ($argument === '<' || $argument === "'") {
                    throw $this->unsupported($i, 'subroutine calls are not allowed');
                }
                if ($argument !== '{' && $argument !== '-') {
                    throw $this->unsupported($i, 'absolute backreferences are not allowed; use \g{-1}');
                }
                $length = $argument === '{' ? $this->braced($path, $i, '/^-\d+$/D') : 2;
                break;
            case 'x':
                $length = $argument === '{' ? $this->braced($path, $i, '/^[0-9A-Fa-f]+$/D') : 2;
                break;
            case 'o':
                $length = $argument === '{' ? $this->braced($path, $i, '/^[0-7]+$/D') : 2;
                break;
            case 'p':
            case 'P':
                if ($argument === '{') {
                    $length = $this->braced($path, $i, '/^\^?[\w &=:-]+$/D');
                } elseif (ctype_alpha($argument)) {
                    $length = 3;
                } else {
                    throw $this->unsupported($i, 'a Unicode property needs a name');
                }
                break;
            default:
                // A backslash at the end of the path leaves the regex unclosed
                $length = min(2, strlen($path) - $i);
        }

        $i += $length;

        return substr($path, $start, $length);
    }

    /**
     * Returns the length of an escape with a braced argument, such as "\x{41}".
     */
    private function braced(string $path, int $i, string $argument): int
    {
        $end = strpos($path, '}', $i + 3);
        if ($end === false || preg_match($argument, substr($path, $i + 3, $end - $i - 3)) !== 1) {
            $escape = substr($path, $i, 2);
            throw $this->unsupported($i, "this form of {$escape}{...} is not allowed");
        }

        return $end - $i + 1;
    }

    /**
     * Reads a character class as PCRE does.
     */
    private function characterClass(string $path, int &$i): string
    {
        $start = $i;
        $length = strlen($path);

        // PCRE skips "\E", "\Q\E" and one "^" before the first character, and a first "]" is literal
        $j = $i + 1;
        $negated = false;
        while (true) {
            if (substr($path, $j, 2) === '\E') {
                $j += 2;
            } elseif (substr($path, $j, 4) === '\Q\E') {
                $j += 4;
            } elseif (!$negated && ($path[$j] ?? '') === '^') {
                $negated = true;
                $j++;
            } else {
                break;
            }
        }
        if (($path[$j] ?? '') === ']') {
            $j++;
        }
        $class = substr($path, $i, $j - $i);
        $i = $j;

        while (true) {
            $run = strcspn($path, '\\[]#', $i);
            $class .= substr($path, $i, $run);
            $i += $run;
            if ($i >= $length) {
                throw new InvalidArgumentException("has an unterminated character class at position {$start}");
            }

            switch ($path[$i]) {
                case ']':
                    $i++;
                    return $class . ']';
                case '[':
                    $end = $this->posixEnd($path, $i);
                    if ($end === 0) {
                        $class .= '['; // Literal: no POSIX syntax starts here
                        $i++;
                        break;
                    }
                    if ($path[$i + 1] !== ':') {
                        throw $this->unsupported($i, 'POSIX collating elements are not supported');
                    }
                    // A POSIX class such as "[:alpha:]" ends with ":]"
                    $class .= substr($path, $i, $end + 2 - $i);
                    $i = $end + 2;
                    break;
                case '#':
                    $class .= '\#';
                    $i++;
                    break;
                default:
                    $class .= $this->escape($path, $i, true);
            }
        }
    }

    /**
     * Returns the offset of the terminator that ends POSIX syntax, such as "[:alpha:]", opened
     * by the "[" at $i inside a class, or 0 if there is none. Follows check_posix_syntax() in
     * PCRE2: it stops at the first "]", so the result never depends on what follows the class.
     */
    private function posixEnd(string $path, int $i): int
    {
        $terminator = $path[$i + 1] ?? '';
        if ($terminator !== ':' && $terminator !== '.' && $terminator !== '=') {
            return 0;
        }

        for ($j = $i + 2, $length = strlen($path); $length - $j >= 2; $j++) {
            $char = $path[$j];
            $next = $path[$j + 1];
            if ($char === '\\' && ($next === ']' || $next === '\\')) {
                $j++;
            } elseif (($char === '[' && $next === $terminator) || $char === ']') {
                return 0;
            } elseif ($char === $terminator && $next === ']') {
                return $j;
            }
        }

        return 0;
    }

    /**
     * Returns the length of the group opener at $i. An inline option setting such as "(?i)"
     * opens no group: it ends with ")".
     */
    private function groupOpener(string $path, int $i): int
    {
        $next = $path[$i + 1] ?? '';
        if ($next === '*') {
            throw $this->unsupported($i, 'backtracking verbs and other (*...) syntax are not allowed');
        }
        if ($next !== '?') {
            return 1;
        }

        $kind = $path[$i + 2] ?? '';
        if ($kind === ':' || $kind === '|' || $kind === '>' || $kind === '=' || $kind === '!') {
            return 3;
        }
        $lookbehind = $path[$i + 3] ?? '';
        if ($kind === '<' && ($lookbehind === '=' || $lookbehind === '!')) {
            return 4;
        }

        $options = strspn($path, self::OPTIONS, $i + 2);
        $char = $path[$i + 2 + $options] ?? '';
        if ($char === ')' || $char === ':') {
            return $options + 3;
        }

        throw $this->unsupported($i, match (true) {
            $char === 'R' || $char === '&' || $char === '+' || ctype_digit($char)
                => 'recursion and subroutine calls are not allowed',
            ($char === '<' && $lookbehind !== '*') || $char === "'" || $char === 'P'
                => 'named groups are not allowed; use a separate parameter',
            $char === '(' => 'conditional groups are not allowed',
            $char === '#' => 'comments are not allowed',
            $char === 'C' => 'callouts are not allowed',
            ctype_alpha($char) => "inline option '$char' is not allowed; only i, m, n, s and U are",
            default => 'this group syntax is not supported',
        });
    }

    private function unsupported(int $at, string $reason): InvalidArgumentException
    {
        return new InvalidArgumentException("uses unsupported regex syntax at position $at: $reason");
    }
}
