<?php

declare(strict_types=1);

namespace Solo\Router;

/**
 * Immutable result of route matching containing the matched route and extracted arguments.
 */
final class MatchResult
{
    /**
     * @param array<string,string> $args
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $args
    ) {
    }
}