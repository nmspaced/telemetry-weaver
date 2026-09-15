<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

use Symfony\Component\Config\ConfigCache;

/**
 * Dump format for route templates.
 *
 * Must stay a flat literal array<string, string> — only that shape opcache
 * keeps immutable in SHM (see PhpFileRouteTemplateProvider). No reading here: any
 * wrapper around the loaded array risks copying it.
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

    /**
     * Checked only in dev — ConfigCache's resource metafile is written in debug mode only.
     */
    public static function isFreshIn(string $dir): bool
    {
        try {
            return new ConfigCache(self::pathIn($dir), true)->isFresh();
        } catch (\Throwable) {
            return false;
        }
    }
}
