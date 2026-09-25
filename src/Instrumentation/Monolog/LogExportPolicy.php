<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

/**
 * What {@see OtelLogHandler} exports. Whether it exports at all is decided in the container.
 */
final readonly class LogExportPolicy
{
    /**
     * @param non-empty-string $level a PSR-3 level name
     * @param list<string> $excludedChannels channels never exported, whatever their level
     */
    public function __construct(
        public string $level = 'info',
        public array $excludedChannels = [],
    ) {}
}
