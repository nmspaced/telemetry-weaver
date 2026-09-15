<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

/**
 * Null object: keeps RouteTemplateProvider non-nullable when no router or dump is available.
 */
final readonly class NullRouteTemplateProvider implements RouteTemplateProvider
{
    #[\Override]
    public function resolve(string $routeName): ?string
    {
        return null;
    }
}
