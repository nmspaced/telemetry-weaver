<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

/**
 * Route templates read once from a PHP dump that opcache keeps in shared memory.
 *
 * `require` returns opcache's immutable array, and holding it in a property does not copy it
 * either, so a worker keeps one pointer into shared memory rather than its own array. Reading
 * the dump on every lookup instead costs a `stat()` per call while opcache validates
 * timestamps — microseconds on a local disk, a hundred or more on a container bind mount, five
 * times per request. Never write to the array.
 */
final readonly class PhpFileRouteTemplateProvider implements RouteTemplateProvider
{
    /**
     * Read once: the dump is written at warmup and does not change during a deploy.
     *
     * @var array<array-key, mixed>
     */
    private array $routeTemplates;

    public function __construct(string $file)
    {
        $this->routeTemplates = self::read($file);
    }

    public static function in(string $dir): self
    {
        return new self(RouteTemplateDump::pathIn($dir));
    }

    #[\Override]
    public function resolve(string $routeName): ?string
    {
        /** @var mixed $template */
        $template = $this->routeTemplates[$routeName] ?? null;

        return \is_string($template) ? $template : null;
    }

    /**
     * @return array<array-key, mixed> empty when the dump is missing or unreadable
     */
    private static function read(string $file): array
    {
        if (!\is_file($file)) {
            return [];
        }

        try {
            /** @var mixed $routeTemplates */
            $routeTemplates = require $file;
        } catch (\Throwable) {
            return [];
        }

        return \is_array($routeTemplates) ? $routeTemplates : [];
    }
}
