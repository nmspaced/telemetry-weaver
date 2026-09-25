<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * A span or log exporter whose `shutdown()` and `forceFlush()` throw, counting every call.
 * Metrics use {@see FailingLifecycleMetricExporter}.
 */
final class FailingLifecycleExporter implements LogRecordExporterInterface, SpanExporterInterface
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
     * Always succeeds, so only the lifecycle calls report failures.
     *
     * @param iterable<mixed> $batch
     *
     * @return FutureInterface<bool>
     */
    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        ++$this->exports;

        return new CompletedFuture(true);
    }

    /**
     * @throws \Throwable always
     */
    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        ++$this->shutdowns;

        throw $this->failure;
    }

    /**
     * @throws \Throwable always
     */
    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        ++$this->flushes;

        throw $this->failure;
    }
}
