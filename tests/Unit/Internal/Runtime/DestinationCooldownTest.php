<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Runtime\DestinationCooldowns;
use Nmspaced\TelemetryWeaver\Internal\Runtime\DestinationShare;
use Nmspaced\TelemetryWeaver\Tests\Support\FlushBudgetTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/** What a send's outcome costs its collector: nothing, the rest of the flush, or a cooldown. */
#[CoversClass(DestinationShare::class)]
#[CoversClass(DestinationCooldowns::class)]
final class DestinationCooldownTest extends FlushBudgetTestCase
{
    #[Test]
    public function aFastFailureNeitherExhaustsNorCoolsDownTheDestination(): void
    {
        $budget = $this->budget(self::A, self::B);
        $budget->begin();

        $this->send($budget, self::A, seconds: 0.01, expected: 0.5, failed: true);
        self::assertAllowance(0.49, $budget->allowance(self::A));
        $budget->end();

        $budget->begin();
        self::assertAllowance(0.5, $budget->allowance(self::A));
    }

    #[Test]
    public function aSlowSuccessUsesUpTheShareWithoutACooldown(): void
    {
        $budget = $this->budget(self::A, self::B);
        $budget->begin();

        $this->send($budget, self::A, seconds: 0.5, expected: 0.5);
        self::assertNull($budget->allowance(self::A));
        $budget->end();

        $budget->begin();
        self::assertAllowance(0.5, $budget->allowance(self::A));
    }

    #[Test]
    public function aTimedOutDestinationCoolsDownOnBoundariesButAFinalFlushStillTriesIt(): void
    {
        $budget = $this->budget(self::A, self::B);
        $budget->begin();
        $this->send($budget, self::A, seconds: 0.5, expected: 0.5, failed: true);
        $budget->end();

        $budget->begin();
        self::assertNull($budget->allowance(self::A));
        self::assertAllowance(1.0, $budget->allowance(self::B), 'a cooling destination is owed no share');
        $budget->end();

        $budget->begin(final: true);
        self::assertAllowance(0.5, $budget->allowance(self::A));
        $budget->end();

        $this->clock->advanceSeconds(0.1);
        $budget->begin();
        self::assertAllowance(0.5, $budget->allowance(self::A), 'the cooldown expires');
    }

    #[Test]
    public function aTimeoutOnScrapsLeftByOthersExhaustsTheShareWithoutACooldown(): void
    {
        $budget = $this->budget(self::A, self::B);
        $budget->begin();
        // Something slow spent most of the budget before this collector's turn.
        $this->clock->advanceSeconds(0.8);

        $this->send($budget, self::A, seconds: 0.1, expected: 0.1, failed: true);
        self::assertNull($budget->allowance(self::A), 'still exhausted for the rest of this flush');
        $budget->end();

        $budget->begin();
        self::assertAllowance(
            0.5,
            $budget->allowance(self::A),
            'a timeout on 100 ms of a 500 ms fair share proves nothing',
        );
    }
}
