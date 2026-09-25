<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Clock\MonotonicClock;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/** @internal Bounded process state: one cooldown deadline per provider, never per request. */
final class SignalFlusher
{
    private int $retryAt = 0;

    /**
     * @param int<0, max> $failureCooldownMilliseconds
     */
    // @mago-expect lint:excessive-parameter-list — the cooldown and clock are defaulted test seams
    public function __construct(
        private readonly TracerProviderInterface|LoggerProviderInterface|MeterProviderInterface $provider,
        private readonly FlushPolicy $flushPolicy,
        private readonly ExportFailureReporter $failures,
        private readonly int $failureCooldownMilliseconds = 30_000,
        private readonly ClockInterface $clock = new MonotonicClock(),
        private readonly ExportBacklog $backlog = new ExportBacklog(),
    ) {}

    /** @return non-empty-string */
    public function signal(): string
    {
        return $this->flushPolicy->signal();
    }

    /** Flushes on the schedule, or early when a full batch waits; never during a cooldown. */
    public function atBoundary(): void
    {
        $this->reportDropped();

        if (
            $this->clock->now() < $this->retryAt
            || !$this->flushPolicy->shouldFlush($this->backlog->holdsFullBatch())
        ) {
            return;
        }

        $this->flush(false);
    }

    /** A final attempt ignores the interval and cooldown; the shared budget still applies. */
    public function atShutdown(): void
    {
        $this->reportDropped();
        $this->flush(true);
    }

    /** Reported before a flush, so it cannot count as that flush's failure. */
    private function reportDropped(): void
    {
        $dropped = $this->backlog->takeDropped();

        if ($dropped === 0) {
            return;
        }

        $this->failures->record(
            'Telemetry export queue overflowed',
            new \OverflowException(\sprintf(
                '%d %s records were dropped: the queue filled up before a boundary could export it',
                $dropped,
                $this->flushPolicy->signal(),
            )),
            ['signal' => $this->flushPolicy->signal(), 'dropped' => $dropped],
        );
    }

    private function flush(bool $shutdown): void
    {
        $started = $this->clock->now();
        $failuresBefore = $this->failures->total();
        try {
            $success = match ($shutdown) {
                true => $this->provider->shutdown(),
                false => $this->provider->forceFlush(),
            };
            if (!$success || $this->failures->total() > $failuresBefore) {
                throw new \RuntimeException('Provider flush failed or its exporter reported a failure');
            }

            $this->retryAt = 0;
        } catch (\Throwable $throwable) {
            $finished = $this->clock->now();
            $this->retryAt = $finished + ($this->failureCooldownMilliseconds * 1_000_000);
            $this->failures->record('Telemetry signal flush failed', $throwable, [
                'signal' => $this->flushPolicy->signal(),
                'phase' => match ($shutdown) {
                    true => 'shutdown',
                    false => 'boundary',
                },
                'duration_ms' => ($finished - $started) / 1_000_000,
                'cooldown_ms' => $this->failureCooldownMilliseconds,
            ]);
        }
    }
}
