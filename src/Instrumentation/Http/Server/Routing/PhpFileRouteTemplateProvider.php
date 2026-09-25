<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

/**
 * Route templates read from a PHP dump that opcache keeps in shared memory, so `require`
 * returns the immutable array without copying it.
 *
 * Keep it that way: do not store the array in a property, never write to it, and use
 * `require` rather than `require_once`. Needs opcache enabled in the running SAPI.
 */
final readonly class PhpFileRouteTemplateProvider implements RouteTemplateProvider
{
    /** Checked once: the dump is written at warmup and does not change during a deploy. */
    private bool $exists;

    public function __construct(
        private string $file,
    ) {
        $this->exists = \is_file($file);
    }

    public static function in(string $dir): self
    {
        return new self(RouteTemplateDump::pathIn($dir));
    }

    #[\Override]
    public function resolve(string $routeName): ?string
    {
        if (!$this->exists) {
            return null;
        }

        try {
            /** @var mixed $routeTemplates */
            $routeTemplates = require $this->file;
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($routeTemplates)) {
            return null;
        }

        /** @var mixed $template */
        $template = $routeTemplates[$routeName] ?? null;

        return \is_string($template) ? $template : null;
    }
}
