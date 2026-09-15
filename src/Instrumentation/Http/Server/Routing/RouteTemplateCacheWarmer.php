<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

use Symfony\Component\Config\ConfigCache;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Writes the "route name => path template" dump on cache warmup.
 *
 * Content is type-checked here, once, rather than on every read.
 */
final readonly class RouteTemplateCacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private ?RouterInterface $router = null,
        private bool $debug = false,
    ) {}

    #[\Override]
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * A warmup failure must not break a deploy — any \Throwable here is swallowed.
     *
     * @return list<string>
     */
    #[\Override]
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->router === null) {
            return [];
        }

        try {
            $collection = $this->router->getRouteCollection();

            $cache = new ConfigCache(RouteTemplateDump::pathIn($buildDir ?? $cacheDir), $this->debug);
            $cache->write(
                RouteTemplateDump::render(self::buildRouteTemplates($collection)),
                $collection->getResources(),
            );
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function buildRouteTemplates(RouteCollection $collection): array
    {
        $routeTemplates = [];

        foreach ($collection as $name => $route) {
            $path = $route->getPath();

            if (!\str_starts_with($path, '/')) {
                continue;
            }

            $routeTemplates[$name] = $path;
        }

        \ksort($routeTemplates);

        return $routeTemplates;
    }
}
