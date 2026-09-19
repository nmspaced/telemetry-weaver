<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;

/**
 * How often a boundary is allowed to drain a signal's queue.
 *
 * The contract is one sentence: **the first boundary of a process flushes, and after that at
 * most one flush per interval.** The state is process-wide and keyed by signal, not held by
 * the instance, because a worker that rebuilds its container between units of work must not
 * restart the schedule with it.
 *
 * It used to skip the first boundary and flush the second immediately, to avoid paying an
 * export in a process that would end right after it. That reasoning no longer describes the
 * callers: `atBoundary()` is reached from exactly two places — a shared HTTP worker's
 * terminate and the Messenger worker loop — and every process that ends after one unit of
 * work (FPM, a kernel-rebuilding worker, any console command) goes through `atShutdown()`,
 * which ignores this policy entirely. So the skip protected nothing, and it cost the first
 * request or message of every worker its visibility: its spans waited not for the interval
 * but for a second boundary to arrive, which in a quiet worker can be a long time.
 *
 * @internal
 */
final class FlushPolicy
{
    /**
     * @var array<string, int> the monotonic time each signal may next be flushed at
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
     * Flush as often as the SDK would have exported this signal on its own.
     *
     * The default, and the only correct one for traces and logs: their processors run with
     * auto-flush off, so the boundary is the only thing that drains their queues. Holding
     * them for the metric interval overflows a 2048-entry queue at a few dozen spans a
     * second.
     *
     * @param non-empty-string $signal "traces", "logs" or "metrics"
     */
    public static function onSdkSchedule(string $signal, ClockInterface $clock = new SystemClock()): self
    {
        return new self($signal, null, $clock);
    }

    /**
     * Flush no more often than this, whatever the SDK's own schedule says.
     *
     * For metrics, where the application configures the boundary cadence directly: every
     * flush is a blocking export, and this is what keeps a busy worker from paying for one
     * at the end of every request.
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
     * The variable the SDK itself would schedule this signal on.
     *
     * Traces and logs are batched with `autoFlush` off, so the boundary is the only thing
     * that drains their queues: they must be drained as often as the SDK would have
     * exported them on its own, not on the metric interval. Sixty seconds of spans
     * overflows a 2048-entry queue at a few dozen spans per second.
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

        return $interval > 0 ? $interval : $default;
    }

    /** @return non-empty-string */
    public function signal(): string
    {
        return $this->signal;
    }

    public function shouldFlush(): bool
    {
        $now = $this->clock->now();

        if ($now < (self::$state[$this->signal] ?? 0)) {
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
