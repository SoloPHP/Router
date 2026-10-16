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

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param callable|array{class-string, string}|string $handler
     * @param array<int, callable|string> $middlewares
     * @param array<string, mixed> $attributes
     * @throws \InvalidArgumentException If an attribute key is an integer or an integer string
     */
    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $group,
        public readonly string $path,
        public readonly mixed $handler,
        private array $middlewares = [],
        private ?string $name = null,
        array $attributes = []
    ) {
        $this->fullPath = $group . $path;

        self::assertAttributeKeys($attributes);
        $this->attributes = $attributes;
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

    /**
     * Sets custom data the router stores but does not interpret, e.g. a required permission.
     *
     * Replaces an earlier value for the same key, including one inherited from a group.
     *
     * @throws \InvalidArgumentException If the key is an integer string, which PHP would store as an integer key
     */
    public function attribute(string $key, mixed $value): self
    {
        self::assertAttributeKeys([$key => $value]);

        $this->attributes[$key] = $value;
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
     * Returns the default only when the key is not set; a stored null is returned as null.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Rejects integer keys, including integer strings such as '42' that PHP stores as integers.
     *
     * @internal Also used by RouteCollector to check group attributes where they enter.
     * @param array<mixed> $attributes
     * @throws \InvalidArgumentException If a key is an integer
     */
    public static function assertAttributeKeys(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if (is_int($key)) {
                throw new \InvalidArgumentException(
                    "Route attribute key '$key' must be a name, not an integer or an integer string"
                );
            }
        }
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
