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
 * @internal The providers this container has actually built — at most one per signal.
 *
 * The container registers every provider it hands out (see `config/services/sdk.php`); the
 * registry depends on no factory. That direction is the point: finalization walks only providers that exist, so a request that
 * never touched metrics does not construct a MeterProvider and an exporter just to shut them
 * down, and there is no `flusher → factory → flusher` cycle in the container.
 *
 * The state is bounded by the three signal names and lives as long as the container. A
 * second provider for the same signal is refused and reported rather than replacing the
 * first, and nothing is accepted once the pipeline has been sealed.
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
     * Adopts a provider for the boundary flush and the final shutdown, and hands it back.
     *
     * The container's provider services are these calls, so the provider the bundle built and one
     * an application configured are registered the same way. A no-op provider is handed back
     * unregistered: there is nothing to deliver or finish, and a `SignalFlusher` for it would only
     * add work to every boundary.
     */
    public function traces(TracerProviderInterface $provider): TracerProviderInterface
    {
        if (!$provider instanceof NoopTracerProvider) {
            $this->add(
                new SignalFlusher(
                    $provider,
                    FlushPolicy::onSdkSchedule('traces'),
                    $this->failures,
                    $this->failureCooldownMilliseconds,
                ),
            );
        }

        return $provider;
    }

    /** @see traces() */
    public function logs(LoggerProviderInterface $provider): LoggerProviderInterface
    {
        if (!$provider instanceof NoopLoggerProvider) {
            $this->add(
                new SignalFlusher(
                    $provider,
                    FlushPolicy::onSdkSchedule('logs'),
                    $this->failures,
                    $this->failureCooldownMilliseconds,
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
