<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;
use Solo\Router\Enums\HttpMethod;

/**
 * Mutable route configuration used by RouteCollector before compilation.
 */
final class RouteDefinition
{
    public readonly string $fullPath;

    /**
     * @internal Created by RouteCollector.
     * @param mixed $handler Opaque application handler; the router does not execute it
     * @param list<callable|string> $middlewares
     * @param array<string, mixed> $attributes
     * @throws InvalidArgumentException If an attribute key is an integer or an integer string
     */
    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $group,
        public readonly string $path,
        public readonly mixed $handler,
        private array $middlewares = [],
        private ?string $name = null,
        private array $attributes = []
    ) {
        $this->fullPath = $group . $path;
        self::assertAttributeKeys($attributes);
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

    /**
     * Sets opaque route data, replacing an earlier value for the same key.
     *
     * @throws InvalidArgumentException If the key is an integer string
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
     * @return list<callable|string>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * A stored null is a value; the default applies only when the key is absent.
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
     * @internal Also validates attributes declared on groups.
     * @param array<mixed> $attributes
     * @throws InvalidArgumentException If a key is an integer
     */
    public static function assertAttributeKeys(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if (is_int($key)) {
                throw new InvalidArgumentException(
                    "Route attribute key '$key' must be a name, not an integer or an integer string"
                );
            }
        }
    }
}
