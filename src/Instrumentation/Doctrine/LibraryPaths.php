<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use Composer\Autoload\ClassLoader;

/**
 * Directories whose code is never the application's: Composer's vendor directory, this
 * bundle's sources, and whatever else is given — generated code, typically.
 *
 * @internal
 */
final readonly class LibraryPaths
{
    /** @var list<non-empty-string> */
    private array $paths;

    /**
     * @param list<string> $paths beside the vendor directory and this bundle's sources
     */
    public function __construct(array $paths = [])
    {
        $this->paths = \array_values(\array_unique(\array_map(
            static fn(string $path): string => \rtrim($path, '/\\') . \DIRECTORY_SEPARATOR,
            \array_filter([...self::own(), ...$paths], static fn(string $path): bool => $path !== ''),
        )));
    }

    public function contains(string $file): bool
    {
        foreach ($this->paths as $path) {
            if (\str_starts_with($file, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> this bundle's sources, and the vendor directory when Composer's
     *     autoloader can be found
     */
    private static function own(): array
    {
        $paths = [\dirname(__DIR__, 2)];

        if (\class_exists(ClassLoader::class, false)) {
            // @mago-expect analysis:unhandled-thrown-type — the class was just found loaded
            $loader = new \ReflectionClass(ClassLoader::class)->getFileName();

            if ($loader !== false) {
                $paths[] = \dirname($loader, 2);
            }
        }

        return $paths;
    }
}
