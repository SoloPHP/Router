<?php

declare(strict_types=1);

namespace Solo\Router;

use Solo\Router\Enums\HttpMethod;

/**
 * A compiled route. Its path includes all group prefixes.
 *
 * Properties are readonly. Arrays are PHP values, so later changes to the definition do
 * not reach a built route; objects, including closures, remain owned by the application.
 */
final readonly class Route
{
    /**
     * @internal Created by Router when a lookup or getter first needs the route.
     * @param mixed $handler Opaque application handler; the router does not execute it
     * @param list<callable|string> $middlewares
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public HttpMethod $method,
        public string $path,
        public mixed $handler,
        public array $middlewares = [],
        public ?string $name = null,
        public array $attributes = []
    ) {
    }

    /**
     * A stored null is a value; the default applies only when the key is absent.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }
}
