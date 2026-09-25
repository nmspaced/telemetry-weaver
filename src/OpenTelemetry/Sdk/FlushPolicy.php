<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;

/**
 * How often a boundary may drain a signal's queue: the first boundary of a process flushes,
 * then once per interval, or sooner when a full batch is waiting, as the SDK schedule does.
 * State is process-wide, so it survives container rebuilds.
 *
 * @internal
 */
final class FlushPolicy
{
    /**
     * @var array<string, int> monotonic time each signal may next be flushed at
     */
    private static array $state = [];

    private readonly int $intervalMilliseconds;

    /**
     * @param non-empty-string $signal "traces", "logs" or "metrics"
     * @param positive-int|null $intervalMilliseconds null follows the signal's own SDK schedule
     */
    private function __construct(
        private readonly string $signal,
        ?int $intervalMilliseconds,
        private readonly ClockInterface $clock,
    ) {
        $this->intervalMilliseconds = $intervalMilliseconds ?? $this->sdkInterval();
    }

    /**
     * Flush as often as the SDK would export on its own; required for traces and logs, whose
     * queues only drain at boundaries.
     *
     * @param non-empty-string $signal "traces", "logs" or "metrics"
     */
    public static function onSdkSchedule(string $signal, ClockInterface $clock = new SystemClock()): self
    {
        return new self($signal, null, $clock);
    }

    /**
     * Flush no more often than the given interval; used for metrics.
     *
     * @param non-empty-string $signal "traces", "logs" or "metrics"
     * @param positive-int $intervalMilliseconds
     */
    public static function every(
        string $signal,
        int $intervalMilliseconds,
        ClockInterface $clock = new SystemClock(),
    ): self {
        return new self($signal, $intervalMilliseconds, $clock);
    }

    /**
     * The SDK schedule delay for this signal.
     *
     * @return positive-int
     */
    private function sdkInterval(): int
    {
        [$variable, $default] = match ($this->signal) {
            'traces' => [Variables::OTEL_BSP_SCHEDULE_DELAY, Defaults::OTEL_BSP_SCHEDULE_DELAY],
            'logs' => [Variables::OTEL_BLRP_SCHEDULE_DELAY, Defaults::OTEL_BLRP_SCHEDULE_DELAY],
            default => [Variables::OTEL_METRIC_EXPORT_INTERVAL, Defaults::OTEL_METRIC_EXPORT_INTERVAL],
        };

        try {
            $interval = Configuration::getInt($variable, $default);
        } catch (\Throwable) {
            $interval = 0;
        }

        if ($interval <= 0) {
            return $default;
        }

        return $interval;
    }

    /** @return non-empty-string */
    public function signal(): string
    {
        return $this->signal;
    }

    /** @param bool $batchReady the queue holds a full export batch */
    public function shouldFlush(bool $batchReady = false): bool
    {
        $now = $this->clock->now();

        if (!$batchReady && $now < (self::$state[$this->signal] ?? 0)) {
            return false;
        }

        self::$state[$this->signal] = $now + ($this->intervalMilliseconds * 1_000_000);

        return true;
    }

    /** @internal the state models a process the test cannot restart */
    public static function resetProcessState(): void
    {
        self::$state = [];
    }
}
