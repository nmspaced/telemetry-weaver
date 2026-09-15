<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

use Symfony\Component\Routing\RouterInterface;

/**
 * Route templates read from the live router — dev fallback when there's no dump.
 *
 * Not used in prod: getRouteCollection() builds the full Route collection in
 * process heap, which is exactly what the dump avoids.
 */
final readonly class RouterRouteTemplateProvider implements RouteTemplateProvider
{
    public function __construct(
        private RouterInterface $router,
    ) {}

    #[\Override]
    public function resolve(string $routeName): ?string
    {
        try {
            return $this->router->getRouteCollection()->get($routeName)?->getPath();
        } catch (\Throwable) {
            return null;
        }
    }
}
