<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * An exporter that throws from `shutdown()` and `forceFlush()`, and counts every call.
 *
 * The existing failing fakes only fail on `export()`, but the lifecycle calls are the ones a
 * boundary makes — and the ones that run while a worker is stopping, where an escaping
 * exception ends the process instead of one batch. The counters are what let a test say the
 * closed gate refused *without reaching the exporter at all*, which is the difference between
 * a gate and a filter.
 *
 * Spans and log records share an `export()` signature, so one fake serves both. Metrics do
 * not — theirs returns a bool and takes no cancellation — and they get
 * {@see FailingLifecycleMetricExporter}.
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
     * Never fails: these tests are about the lifecycle calls, and a failing export would
     * report a second time and blur which call the report belongs to.
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
