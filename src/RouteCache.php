<?php

declare(strict_types=1);

namespace Solo\Router;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Keeps a built router in a PHP file. With OPcache, loading it costs almost nothing:
 * routes are neither registered nor built again.
 *
 * The file is never compared with the route definitions. Delete it, e.g. with clear(),
 * whenever routes change, and leave the cache off during development.
 */
final readonly class RouteCache
{
    public function __construct(private string $file)
    {
    }

    /**
     * Returns the cached router, or builds one with $define and caches it.
     *
     * @param callable(RouteCollector): void $define Registers the routes; not called when the cache is used
     * @throws LogicException If a route holds a value that cannot be cached, such as a closure
     * @throws RuntimeException If the cache file cannot be written
     */
    public function load(callable $define): Router
    {
        $router = $this->read();
        if ($router !== null) {
            return $router;
        }

        $routes = new RouteCollector();
        $define($routes);
        $router = $routes->build();
        $this->save($router);

        return $router;
    }

    /**
     * Writes the router to the cache file. Concurrent requests never read a partial file.
     *
     * @throws LogicException If a route holds a value that cannot be cached, such as a closure
     * @throws RuntimeException If the cache file cannot be written
     */
    public function save(Router $router): void
    {
        $code = '<?php return ' . var_export($router->toArray(), true) . ";\n";
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the route cache directory $directory");
        }

        // A file renamed within its directory replaces the old one atomically
        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $code) === false || !@rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException("Cannot write the route cache file {$this->file}");
        }
        $this->invalidate();
    }

    /**
     * Deletes the cache file, so the next load() builds the router again.
     *
     * @throws RuntimeException If the cache file cannot be deleted
     */
    public function clear(): void
    {
        if (is_file($this->file) && !@unlink($this->file)) {
            throw new RuntimeException("Cannot delete the route cache file {$this->file}");
        }
        $this->invalidate();
    }

    private function read(): ?Router
    {
        if (!is_file($this->file)) {
            return null;
        }

        // Included in a scope of its own: the file only returns data. Silenced because
        // clear() in another process may delete the file after is_file().
        $data = (static fn(string $file): mixed => @include $file)($this->file);
        if (!is_array($data)) {
            return null;
        }

        try {
            return Router::fromArray($data);
        } catch (InvalidArgumentException) {
            return null; // Written by an incompatible version: build again
        }
    }

    /** OPcache may not check timestamps in production, so a rewritten file must be invalidated. */
    private function invalidate(): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->file, true);
        }
    }
}
