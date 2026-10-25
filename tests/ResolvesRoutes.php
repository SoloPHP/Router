<?php

declare(strict_types=1);

namespace Solo\Router\Test;

use Solo\Router\Results\Matched;
use Solo\Router\RouteCollector;
use Solo\Router\Router;

/** Keeps the grammar assertions independent of the public result representation. */
trait ResolvesRoutes
{
    private function match(RouteCollector|Router $router, string $method, string $uri): array|false
    {
        if ($router instanceof RouteCollector) {
            $router = $router->build();
        }

        $result = $router->resolve($method, $uri);
        if (!$result instanceof Matched) {
            return false;
        }

        return [
            'handler' => $result->route->handler,
            'params' => $result->params,
            'middlewares' => $result->route->middlewares,
            'name' => $result->route->name,
            'attributes' => $result->route->attributes,
        ];
    }
}
