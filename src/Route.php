<?php

declare(strict_types=1);

namespace Solo\Router;

use Solo\Router\Enums\HttpMethod;

/**
 * Route definition with fluent configuration API.
 */
final class Route
{
    /**
     * @param callable|array{class-string, string}|string $handler
     * @param array<int, callable|string> $middlewares
     */
    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $group,
        public readonly string $path,
        public readonly mixed $handler,
        private array $middlewares = [],
        private ?string $name = null
    ) {
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @param callable|string ...$middlewares
     */
    public function middleware(callable|string ...$middlewares): self
    {
        $this->middlewares = array_values([...$this->middlewares, ...$middlewares]);
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return array<int, callable|string>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}
