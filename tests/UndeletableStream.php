<?php

declare(strict_types=1);

namespace Solo\Router\Test;

/** A stream wrapper whose paths all name existing files that cannot be deleted. */
final class UndeletableStream
{
    /** Set by PHP */
    public mixed $context;

    /** @return array{mode: int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100644];
    }

    public function unlink(string $path): bool
    {
        return false;
    }
}
