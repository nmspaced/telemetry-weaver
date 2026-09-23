<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;

/**
 * The metric counterpart of {@see FailingLifecycleExporter}. Separate because
 * `MetricExporterInterface::export()` returns a bool and takes no cancellation, so the two
 * cannot be one class.
 */
final class FailingLifecycleMetricExporter implements PushMetricExporterInterface
{
    public int $exports = 0;

    public int $shutdowns = 0;

    public int $flushes = 0;

    public function __construct(
        private readonly \Throwable $failure = new \RuntimeException('collector is gone'),
    ) {}

    public function calls(): int
    {
        return $this->exports + $this->shutdowns + $this->flushes;
    }

    /**
     * @param iterable<mixed> $batch
     */
    #[\Override]
    public function export(iterable $batch): bool
    {
        ++$this->exports;

        return true;
    }

    /**
     * @throws \Throwable always
     */
    #[\Override]
    public function shutdown(): bool
    {
        ++$this->shutdowns;

        throw $this->failure;
    }

    /**
     * @throws \Throwable always
     */
    #[\Override]
    public function forceFlush(): bool
    {
        ++$this->flushes;

        throw $this->failure;
    }

    public function temporality(MetricMetadataInterface $_metric): Temporality|string|null
    {
        return Temporality::DELTA;
    }
}
