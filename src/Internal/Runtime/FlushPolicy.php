<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;

final class FlushPolicy
{
    /**
     * @var array<string, array{reused: bool, nextAt: int}>
     */
    private static array $state = [];

    private readonly int $intervalMilliseconds;

    /**
     * @param non-empty-string $signal "traces", "logs" or "metrics"
     * @param positive-int|null $intervalMilliseconds null follows the signal's own SDK schedule
     */
    public function __construct(
        private readonly string $signal,
        ?int $intervalMilliseconds = null,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->intervalMilliseconds = $intervalMilliseconds ?? $this->sdkInterval();
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
        self::$state[$this->signal] ??= ['reused' => false, 'nextAt' => 0];
        $state = self::$state[$this->signal];

        if (!$state['reused']) {
            self::$state[$this->signal]['reused'] = true;

            return false;
        }

        $now = $this->clock->now();

        if ($now < $state['nextAt']) {
            return false;
        }

        self::$state[$this->signal]['nextAt'] = $now + ($this->intervalMilliseconds * 1_000_000);

        return true;
    }

    /** @internal the state models a process the test cannot restart */
    public static function resetProcessState(): void
    {
        self::$state = [];
    }
}
