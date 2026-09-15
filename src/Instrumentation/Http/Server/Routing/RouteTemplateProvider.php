<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

/**
 * Route name to path template ("/orders/{id}") mapping. Source differs between prod (dump) and dev (router).
 *
 * @internal
 */
interface RouteTemplateProvider
{
    /**
     * @return string|null null means "route unknown", not an error
     */
    public function resolve(string $routeName): ?string;
}
