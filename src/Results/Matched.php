<?php

declare(strict_types=1);

namespace Solo\Router\Results;

use Solo\Router\Route;

final readonly class Matched
{
    /** @param array<string, string> $params */
    public function __construct(public Route $route, public array $params)
    {
    }
}
