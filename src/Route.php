<?php

declare(strict_types=1);

namespace Solo\Router;

use Solo\Router\Enums\HttpMethod;

/**
 * Immutable data transfer object representing a single route definition.
 */
final class Route
{
    /**
     * @param callable|array{class-string, string}|string $handler
     * @param array<int, callable> $middlewares
     */
    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $group,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly array $middlewares,
        public readonly ?string $name
    ) {
    }
}
