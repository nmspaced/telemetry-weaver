<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

/**
 * What {@see OtelLogHandler} exports, as opposed to whether it exports at all.
 *
 * Whether records are exported is decided in the container: the handler is registered or it
 * is not, so there is no on/off flag here.
 */
final readonly class LogExportPolicy
{
    /**
     * @param non-empty-string $level a PSR-3 level name; the configuration tree is what
     *                                constrains it to one of the eight
     * @param list<string> $excludedChannels channels never exported, whatever their level
     */
    public function __construct(
        public string $level = 'info',
        public array $excludedChannels = [],
    ) {}
}
