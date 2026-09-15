<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\NullRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RequestRouteTemplateResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Per-request decisions independent of the scope lifecycle: whether it needs a span and what to name it.
 */
final readonly class RequestPolicy
{
    /**
     * @param list<non-empty-string> $excludedPaths path prefixes, each starting with "/"
     */
    public function __construct(
        private array $excludedPaths = [],
        private RequestRouteTemplateResolver $routeTemplateResolver = new RequestRouteTemplateResolver(
            new NullRouteTemplateProvider(),
        ),
    ) {}

    public function isExcluded(Request $request): bool
    {
        return \array_any($this->excludedPaths, static fn(string $prefix): bool => \str_starts_with(
            $request->getPathInfo(),
            $prefix,
        ));
    }

    /**
     * @return string|null null means the route wasn't resolved, not an error
     */
    public function routeTemplate(Request $request): ?string
    {
        return $this->routeTemplateResolver->resolve($request);
    }
}
