<?php

declare(strict_types=1);

namespace Solo\Router;

use Solo\Router\Enums\HttpMethod;

/**
 * Route definition with fluent configuration API.
 */
final class Route
{
    /** @var array<int, callable|string> */
    private array $middlewares;
    private ?string $name;

    /**
     * @param callable|array{class-string, string}|string $handler
     * @param array<int, callable|string> $middlewares
     */
    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $group,
        public readonly string $path,
        public readonly mixed $handler,
        array $middlewares = [],
        ?string $name = null
    ) {
        $this->middlewares = $middlewares;
        $this->name = $name;
    }

    /**
     * Set the route name.
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Add middleware(s) to the route.
     *
     * @param callable|string ...$middlewares
     */
    public function middleware(callable|string ...$middlewares): self
    {
        $this->middlewares = array_values([...$this->middlewares, ...$middlewares]);
        return $this;
    }

    /**
     * Get the route name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get all middlewares.
     *
     * @return array<int, callable|string>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}
