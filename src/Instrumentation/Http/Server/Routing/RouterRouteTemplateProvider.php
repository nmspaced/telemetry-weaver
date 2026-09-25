<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

use Symfony\Component\Routing\RouterInterface;

/**
 * Route templates from the live router, used in development when there is no dump.
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
