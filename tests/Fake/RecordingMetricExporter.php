<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;

/**
 * Exporter that records batches and can fail on command.
 */
final class RecordingMetricExporter implements PushMetricExporterInterface
{
    /**
     * @var list<list<string>> metric names of each received batch
     */
    public array $batches = [];

    public int $flushes = 0;

    public int $shutdowns = 0;

    public function __construct(
        private readonly bool $succeeds = true,
        private readonly ?\Throwable $throws = null,
    ) {}

    /**
     * @param iterable<Metric> $batch
     *
     * @throws \Throwable if the instance was created with an exception
     */
    #[\Override]
    public function export(iterable $batch): bool
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $names = [];

        foreach ($batch as $metric) {
            $names[] = $metric->name;
        }

        $this->batches[] = $names;

        return $this->succeeds;
    }

    #[\Override]
    public function shutdown(): bool
    {
        ++$this->shutdowns;

        return $this->succeeds;
    }

    #[\Override]
    public function forceFlush(): bool
    {
        ++$this->flushes;

        return $this->succeeds;
    }
}
