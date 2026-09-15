<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

use Symfony\Component\HttpFoundation\Request;

/**
 * Route template for a request — the value semconv requires in http.route and in the span name.
 */
final readonly class RequestRouteTemplateResolver
{
    public function __construct(
        private RouteTemplateProvider $routeTemplateProvider,
    ) {}

    public function resolve(Request $request): ?string
    {
        /** @var mixed $routeName */
        $routeName = $request->attributes->get('_route');

        if (!\is_string($routeName) || $routeName === '') {
            return null;
        }

        return $this->routeTemplateProvider->resolve($routeName);
    }
}
