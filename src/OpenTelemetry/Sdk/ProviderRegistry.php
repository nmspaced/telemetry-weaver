<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * @internal
 *
 * The providers this container actually built, at most one per signal, so finalization
 * never constructs a provider just to shut it down.
 */
final class ProviderRegistry
{
    /** @var array<non-empty-string, SignalFlusher> */
    private array $signals = [];

    /**
     * @param int<0, max> $failureCooldownMilliseconds
     * @param positive-int|null $metricIntervalMilliseconds
     */
    public function __construct(
        private readonly ExportGate $gate,
        private readonly ExportFailureReporter $failures,
        private readonly int $failureCooldownMilliseconds = 30_000,
        private readonly ?int $metricIntervalMilliseconds = null,
    ) {}

    /**
     * Registers a provider for boundary flushes and shutdown and returns it. No-op providers
     * are returned unregistered.
     *
     * @param ExportBacklog $backlog what the provider's batch processor holds; a provider the
     *                               bundle did not build has none to track
     */
    public function traces(
        TracerProviderInterface $provider,
        ExportBacklog $backlog = new ExportBacklog(),
    ): TracerProviderInterface {
        if (!$provider instanceof NoopTracerProvider) {
            $this->add(
                new SignalFlusher(
                    $provider,
                    FlushPolicy::onSdkSchedule('traces'),
                    $this->failures,
                    $this->failureCooldownMilliseconds,
                    backlog: $backlog,
                ),
            );
        }

        return $provider;
    }

    /** @see traces() */
    public function logs(
        LoggerProviderInterface $provider,
        ExportBacklog $backlog = new ExportBacklog(),
    ): LoggerProviderInterface {
        if (!$provider instanceof NoopLoggerProvider) {
            $this->add(
                new SignalFlusher(
                    $provider,
                    FlushPolicy::onSdkSchedule('logs'),
                    $this->failures,
                    $this->failureCooldownMilliseconds,
                    backlog: $backlog,
                ),
            );
        }

        return $provider;
    }

    /** @see traces() */
    public function metrics(MeterProviderInterface $provider): MeterProviderInterface
    {
        if (!$provider instanceof NoopMeterProvider) {
            $this->add(
                new SignalFlusher(
                    $provider,
                    $this->metricIntervalMilliseconds === null
                        ? FlushPolicy::onSdkSchedule('metrics')
                        : FlushPolicy::every('metrics', $this->metricIntervalMilliseconds),
                    $this->failures,
                    $this->failureCooldownMilliseconds,
                ),
            );
        }

        return $provider;
    }

    public function add(SignalFlusher $signal): void
    {
        if ($this->isClosed()) {
            return;
        }

        $name = $signal->signal();
        if (!\in_array($name, ['traces', 'logs', 'metrics'], true) || ($this->signals[$name] ?? null) !== null) {
            $this->failures->record('Duplicate or unsupported telemetry provider', new \LogicException($name));

            return;
        }

        $this->signals[$name] = $signal;
    }

    /** @return iterable<SignalFlusher> */
    public function ordered(): iterable
    {
        foreach (['traces', 'logs', 'metrics'] as $name) {
            $signal = $this->signals[$name] ?? null;
            if ($signal === null) {
                continue;
            }

            yield $signal;
        }
    }

    public function isClosed(): bool
    {
        return $this->gate->isClosed();
    }

    public function finishOnExit(BoundaryFlush $flusher): void
    {
        $this->gate->finishOnExit($flusher);
    }

    public function discard(): void
    {
        $this->gate->close();
        $this->signals = [];
    }
}
