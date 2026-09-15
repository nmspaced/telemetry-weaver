<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateProvider;

/**
 * A fixed route name to template mapping, standing in for the router dump.
 */
final readonly class StaticRouteTemplateProvider implements RouteTemplateProvider
{
    /** @param array<string, string> $templates */
    public function __construct(
        private array $templates = [],
    ) {}

    #[\Override]
    public function resolve(string $routeName): ?string
    {
        return $this->templates[$routeName] ?? null;
    }
}
