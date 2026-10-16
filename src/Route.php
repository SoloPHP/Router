<?php

declare(strict_types=1);

namespace Solo\Router;

use Closure;
use Solo\Router\Enums\HttpMethod;

/**
 * Route definition with fluent configuration API.
 */
final class Route
{
    /** Group prefix followed by the route path. */
    public readonly string $fullPath;

    /** @var list<Closure(self, string): void> */
    private array $nameListeners = [];

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
        $this->fullPath = $group . $path;
    }

    /**
     * @throws \InvalidArgumentException If a router holding this route already has a route with this name
     */
    public function name(string $name): self
    {
        foreach ($this->nameListeners as $listener) {
            $listener($this, $name);
        }

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

    /**
     * Registers a listener called before the name changes; it may throw to reject the name.
     *
     * @internal Used by Router to keep its name index in sync.
     * @param Closure(self, string): void $listener
     */
    public function onNameChange(Closure $listener): void
    {
        $this->nameListeners[] = $listener;
    }
}
