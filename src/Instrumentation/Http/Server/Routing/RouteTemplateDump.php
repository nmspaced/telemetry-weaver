<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

use Symfony\Component\Config\ConfigCache;

/**
 * Dump format for route templates: a flat literal `array<string, string>`, the only shape
 * opcache keeps immutable in shared memory.
 */
final readonly class RouteTemplateDump
{
    public const string FILE_NAME = 'route_templates.php';

    public static function pathIn(string $dir): string
    {
        return \rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /**
     * @param array<string, string> $routeTemplates
     */
    public static function render(array $routeTemplates): string
    {
        return \sprintf("<?php\n\nreturn %s;\n", \var_export($routeTemplates, true));
    }

    /** Checked only in debug mode, where `ConfigCache` writes resource metadata. */
    public static function isFreshIn(string $dir): bool
    {
        try {
            return new ConfigCache(self::pathIn($dir), true)->isFresh();
        } catch (\Throwable) {
            return false;
        }
    }
}
