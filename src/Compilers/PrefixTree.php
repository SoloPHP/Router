<?php

declare(strict_types=1);

namespace Solo\Router\Compilers;

/**
 * An order-preserving prefix tree of route keys, compiled to a single regex.
 *
 * PCRE tries alternatives from left to right, so routes are listed in registration order.
 * Routes with a common prefix share it, which lets PCRE skip whole subtrees at once.
 * A route moves ahead of earlier routes only when the first bytes where they differ are
 * literals, because then no URI matches both. Shared prefixes contain only literal bytes
 * and groups that consume exactly one segment, so sharing never changes the route that
 * backtracking reaches first.
 *
 * @internal
 */
final class PrefixTree
{
    /** @var list<string> A key starting with the node's prefix */
    private array $keys = [''];

    /** @var list<int> Prefix length; a route uses its whole key */
    private array $lengths = [0];

    /** @var list<int|null> Route ID of a leaf, null for a prefix node */
    private array $routeIds = [null];

    /** @var array<int, array<int, int>> Children of a prefix node, in matching order */
    private array $children = [[]];

    public function __construct(private readonly PatternCompiler $compiler)
    {
    }

    public function add(string $key, int $routeId): void
    {
        $length = strlen($key);
        $node = $this->node($key, $length, $routeId, []);
        $parent = 0;

        while (true) {
            $base = $this->lengths[$parent];
            $first = $key[$base] ?? PatternCompiler::MARKER;

            for ($i = count($this->children[$parent]) - 1; $i >= 0; $i--) {
                $sibling = $this->children[$parent][$i];
                $siblingKey = $this->keys[$sibling];
                $siblingFirst = $siblingKey[$base] ?? PatternCompiler::MARKER;
                if ($first !== $siblingFirst) {
                    // Different literal bytes at the same offset: no URI matches both routes.
                    if ($first !== PatternCompiler::MARKER && $siblingFirst !== PatternCompiler::MARKER) {
                        continue;
                    }
                    break;
                }

                $siblingLength = $this->lengths[$sibling];
                $common = PatternCompiler::commonPrefix($key, $siblingKey, $base, min($length, $siblingLength));
                if ($common === $base) {
                    break;
                }
                if ($this->routeIds[$sibling] === null && $common === $siblingLength) {
                    $parent = $sibling;
                    continue 2;
                }
                $this->children[$parent][$i] = $this->node($key, $common, null, [$sibling, $node]);
                return;
            }

            $this->children[$parent][] = $node;
            return;
        }
    }

    /** Branch reset numbers the groups of every route as in its standalone pattern. */
    public function regex(): string
    {
        return '#^(?|' . $this->alternation(0) . ')#D';
    }

    private function alternation(int $parent): string
    {
        $from = $this->lengths[$parent];
        $alternatives = [];
        foreach ($this->children[$parent] as $node) {
            $prefix = $this->compiler->regex($this->keys[$node], $from, $this->lengths[$node]);
            $routeId = $this->routeIds[$node];
            $alternatives[] = $routeId === null
                ? $prefix . '(?|' . $this->alternation($node) . ')'
                : $prefix . '$(*:' . $routeId . ')';
        }

        return implode('|', $alternatives);
    }

    /** @param list<int> $children */
    private function node(string $key, int $length, ?int $routeId, array $children): int
    {
        $this->keys[] = $key;
        $this->lengths[] = $length;
        $this->routeIds[] = $routeId;
        $this->children[] = $children;

        return count($this->keys) - 1;
    }
}
