<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

use Symfony\Component\Routing\RouterInterface;

/**
 * Picks the route template source once, at service build time.
 */
final readonly class RouteTemplateProviderFactory
{
    public static function create(
        string $buildDir,
        ?RouterInterface $router = null,
        bool $debug = false,
    ): RouteTemplateProvider {
        if (!$debug) {
            return PhpFileRouteTemplateProvider::in($buildDir);
        }

        if (RouteTemplateDump::isFreshIn($buildDir)) {
            return PhpFileRouteTemplateProvider::in($buildDir);
        }

        return $router === null ? new NullRouteTemplateProvider() : new RouterRouteTemplateProvider($router);
    }
}
