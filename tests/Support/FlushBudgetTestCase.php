<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SendAllowance;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * @internal A 1000 ms flush budget with a 100 ms cooldown on a frozen clock, and sends that take a chosen time.
 */
abstract class FlushBudgetTestCase extends TestCase
{
    protected const string A = 'http://collector-a:4318';

    protected const string B = 'http://collector-b:4318';

    protected const string C = 'http://collector-c:4318';

    protected FrozenClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
    }

    protected function budget(string ...$destinations): FlushBudget
    {
        $budget = new FlushBudget(1000, $this->clock, 100);
        foreach ($destinations as $destination) {
            $budget->register($destination);
        }

        return $budget;
    }

    protected function send(
        FlushBudget $budget,
        string $destination,
        float $seconds,
        float $expected,
        bool $failed = false,
    ): void {
        $allowance = $budget->allowance($destination);
        self::assertAllowance($expected, $allowance);
        $this->clock->advanceSeconds($seconds);
        $failed ? $allowance?->failed() : $allowance?->succeeded();
    }

    protected static function assertAllowance(float $seconds, ?SendAllowance $allowance, string $message = ''): void
    {
        self::assertNotNull($allowance, $message);
        self::assertEqualsWithDelta($seconds, $allowance->seconds(), 1e-6, $message);
    }
}
