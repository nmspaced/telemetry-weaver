<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

/**
 * Maps a route name to its path template (`/orders/{id}`): from a dump in production, from
 * the router in development.
 *
 * @internal
 */
interface RouteTemplateProvider
{
    /**
     * @return string|null null when the route is unknown
     */
    public function resolve(string $routeName): ?string;
}
