<?php

declare(strict_types=1);

namespace Solo\Router\Results;

final readonly class MethodNotAllowed
{
    /** @param list<string> $allowedMethods */
    public function __construct(public array $allowedMethods)
    {
    }
}
