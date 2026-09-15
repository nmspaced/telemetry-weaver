<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Diagnostics;

use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class InstrumentationFailureReporter
{
    private RateLimiter $limiter;

    /**
     * @param int $detailedPerProcess how many initial events to log in full
     * @param float $minIntervalSeconds minimum pause between subsequent lines
     */
    public function __construct(
        private LoggerInterface $logger,
        int $detailedPerProcess = 10,
        float $minIntervalSeconds = 60.0,
        ClockInterface $clock = new SystemClock(),
    ) {
        $this->limiter = new RateLimiter($detailedPerProcess, $minIntervalSeconds, $clock);
    }

    /**
     * @param string $what a short, bounded description of the violation
     * @param string $where the span name or Symfony event the failure belongs to
     */
    public function report(string $what, string $where, ?\Throwable $cause = null): void
    {
        try {
            if (!$this->limiter->allow()) {
                return;
            }

            $this->logger->warning(
                \sprintf(
                    'OpenTelemetry lifecycle: %s at "%s"%s (%d total in this process)',
                    $what,
                    $where,
                    $cause === null ? '' : ': ' . $cause->getMessage(),
                    $this->limiter->total(),
                ),
                $cause === null ? [] : ['exception' => $cause],
            );
        } catch (\Throwable) {
            return;
        }
    }

    public function total(): int
    {
        return $this->limiter->total();
    }
}
