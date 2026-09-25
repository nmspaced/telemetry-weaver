<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Runtime;

use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * Worker heap (`php.memory.usage`) and uptime (`php.worker.uptime`), sampled at each export.
 *
 * Named `php.*` because these are the Zend allocator heap and time since the first unit of
 * work, not the process values of the conventions. Registered on the first request or worker
 * loop, never by one-shot commands or request-per-process runtimes.
 */
final class ProcessMetrics
{
    public const string MEMORY_USAGE = 'php.memory.usage';

    public const string UPTIME = 'php.worker.uptime';

    /** @var list<ObservableCallbackInterface> */
    private array $callbacks = [];

    private readonly int $startedAt;

    public function __construct(
        private readonly Metrics $metrics,
        private readonly SymfonyRuntimeProfile $runtime,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->startedAt = $clock->now();
    }

    /** Idempotent: the first unit of work starts the reporting. */
    public function register(): void
    {
        if ($this->callbacks !== [] || !$this->runtime->recordsWorkerMetrics()) {
            return;
        }

        $this->callbacks[] = $this->metrics->observableUpDownCounter(
            self::MEMORY_USAGE,
            static function (ObserverInterface $observer): void {
                $observer->observe(\memory_get_usage(true));
            },
            'By',
            'Memory the Zend allocator currently holds for this worker.',
        );

        $this->callbacks[] = $this->metrics->observableGauge(
            self::UPTIME,
            function (ObserverInterface $observer): void {
                $observer->observe($this->uptime());
            },
            's',
            'Time since this worker served its first piece of work.',
        );
    }

    /** Monotonic, measured from the first unit of work rather than process boot. */
    private function uptime(): float
    {
        return \max(0, $this->clock->now() - $this->startedAt) / ClockInterface::NANOS_PER_SECOND;
    }
}
