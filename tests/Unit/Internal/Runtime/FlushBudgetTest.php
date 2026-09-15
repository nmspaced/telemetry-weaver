<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushWindow;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SendAllowance;
use Nmspaced\TelemetryWeaver\Tests\Support\FlushBudgetTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/** How a flush divides its budget between collectors. */
#[CoversClass(FlushBudget::class)]
#[CoversClass(FlushWindow::class)]
#[CoversClass(SendAllowance::class)]
final class FlushBudgetTest extends FlushBudgetTestCase
{
    #[Test]
    public function aShareIsTheTimeLeftDividedByTheDestinationsNotYetServed(): void
    {
        $budget = $this->budget(self::A, self::B, self::C);
        self::assertNull($budget->allowance(self::A), 'outside a flush there is nothing to allow');
        self::assertNull($budget->remainingSeconds());
        $budget->begin();

        $this->send($budget, self::A, seconds: 0.1, expected: 1 / 3);
        // A fast collector's unused share rolls over to the ones after it.
        $this->send($budget, self::B, seconds: 0.45, expected: 0.45, failed: true);
        self::assertAllowance(0.45, $budget->allowance(self::C));
    }

    #[Test]
    public function aTimedOutDestinationIsRefusedForTheRestOfTheFlushWhileOthersKeepTheirShare(): void
    {
        $budget = $this->budget(self::A, self::B);
        $budget->begin();

        // A client's timeout fires slightly before the allowance it was given.
        $this->send($budget, self::A, seconds: 0.492, expected: 0.5, failed: true);

        self::assertNull($budget->allowance(self::A), 'a second signal to the same collector must not wait again');
        self::assertAllowance(0.508, $budget->allowance(self::B));
    }

    #[Test]
    public function anAllowanceTooShortToCompleteASendIsNotGranted(): void
    {
        $budget = $this->budget(self::A);
        $budget->begin();

        $this->clock->advanceSeconds(0.996);

        self::assertNull($budget->allowance(self::A));
        $this->clock->advanceSeconds(0.003);
        self::assertNull($budget->allowance(self::A), 'the refused share stays exhausted');
        $budget->end();

        $budget->begin();
        self::assertAllowance(1.0, $budget->allowance(self::A), 'and it started no cooldown');
    }

    #[Test]
    public function anOutcomeIsCountedOnce(): void
    {
        $budget = $this->budget(self::A, self::B);
        $budget->begin();

        $allowance = $budget->allowance(self::A);
        self::assertNotNull($allowance);
        $this->clock->advanceSeconds(0.01);
        $allowance->failed();
        $this->clock->advanceSeconds(0.49);
        $allowance->failed();

        self::assertNull($budget->allowance(self::A), 'the share ran out on the clock');
        $budget->end();
        $budget->begin();
        self::assertNotNull($budget->allowance(self::A), 'the second report must not start a cooldown');
    }
}
