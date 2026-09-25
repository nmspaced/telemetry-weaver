<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Diagnostics;

use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs export failures, rate-limited. It runs inside the exporters' catch blocks, so
 * nothing it does, logging included, may throw.
 */
final readonly class ExportFailureReporter
{
    private RateLimiter $limiter;

    /**
     * @param int $detailedPerProcess how many initial failures are logged without delay
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

    /** @param array<string, string|int|float> $details */
    public function record(string $message, \Throwable $e, array $details = []): void
    {
        try {
            if (!$this->limiter->allow()) {
                return;
            }

            $context = [
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
                'failures_so_far' => $this->limiter->total(),
                ...$details,
            ];

            $previous = $e->getPrevious();

            if ($previous !== null) {
                $context['previous'] = $previous->getMessage();
                $context['previous_class'] = $previous::class;
            }

            $this->logger->warning($message, $context);
        } catch (\Throwable) {
            return;
        }
    }

    /** Total failures, including those not logged. */
    public function total(): int
    {
        return $this->limiter->total();
    }
}
