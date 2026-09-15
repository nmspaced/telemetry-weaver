<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing;

/**
 * Route templates read from an opcache-resident dump.
 *
 * The dump is a literal array<string, string>, so opcache keeps it in shared
 * memory as immutable (GC_IMMUTABLE): `return [...]` hands back a pointer,
 * not a copy. Measured on 5000 routes (263 KB dump): 20 concurrently held
 * results cost 920 bytes total, 200 calls cost 0.01 ms — with opcache off,
 * the same 20 cost 16 MB and 200 calls cost 157 ms.
 *
 * Invariants that keep this true:
 *  - No memoization of the array in a property (require is free once opcached).
 *  - No writes to the loaded array (array_filter, sort, `$map[$x] =` all
 *    detach it from SHM and copy it into worker heap — 327 KB on this dump).
 *  - `require`, not `require_once` (the second call would return true).
 *
 * Requires opcache enabled in the running SAPI (opcache.enable_cli=1 for
 * RoadRunner, opcache.enable for FrankenPHP).
 */
final readonly class PhpFileRouteTemplateProvider implements RouteTemplateProvider
{
    /**
     * Whether the dump was there when this provider was built.
     *
     * A stat is a syscall opcache cannot elide, and it sat in front of every
     * single resolve. The dump is written at warmup and the build directory is
     * immutable for the life of a deploy, so the answer cannot change under a
     * running process — and the one case where it could, a dev dump that is
     * not yet fresh, never reaches this provider: RouteTemplateProviderFactory
     * hands back the router-backed one instead.
     *
     * Caching this bool is not the memoization the note above forbids; that
     * one is about the loaded array, which must keep coming from opcache.
     */
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
