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
    public function __construct(
        private readonly TracerProviderInterface|LoggerProviderInterface|MeterProviderInterface $provider,
        private readonly FlushPolicy $flushPolicy,
        private readonly ExportFailureReporter $failures,
        private readonly int $failureCooldownMilliseconds = 30_000,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {}

    /** @return non-empty-string */
    public function signal(): string
    {
        return $this->flushPolicy->signal();
    }

    public function atBoundary(): void
    {
        if ($this->clock->now() < $this->retryAt || !$this->flushPolicy->shouldFlush()) {
            return;
        }

        $this->flush(false);
    }

    /** A final attempt ignores the interval and cooldown; the shared budget still applies. */
    public function atShutdown(): void
    {
        $this->flush(true);
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
