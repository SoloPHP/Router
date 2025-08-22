<?php

declare(strict_types=1);

namespace Solo\Router;

/**
 * Immutable data transfer object representing a single route definition.
 */
final class Route
{
    /**
     * @param array<callable> $middlewares
     */
    public function __construct(
        public readonly string $method,
        public readonly string $group,
        public readonly string $path,
        /** @var callable|array|string */
        public readonly mixed $handler,
        /** @var array<callable> */
        public readonly array $middlewares,
        public readonly ?string $name
    ) {
    }
}
