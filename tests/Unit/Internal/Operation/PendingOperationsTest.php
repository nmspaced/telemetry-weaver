<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Internal\Operation\PendingOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingOperations::class)]
final class PendingOperationsTest extends TestCase
{
    #[Test]
    public function aFinishedOperationIsNotAbandonedLater(): void
    {
        $error = new \RuntimeException('boom');
        $operation = $this->createMock(RunningOperation::class);
        $operation->expects(self::once())->method('finish')->with($error);
        $operation->expects(self::never())->method('abandon');

        $pending = new PendingOperations();
        $pending->add($operation);
        $pending->finish($operation, $error);
        $pending->abandonAll();
    }

    #[Test]
    public function anAbandonedOperationIsAbandonedOnce(): void
    {
        $operation = $this->createMock(RunningOperation::class);
        $operation->expects(self::never())->method('finish');
        $operation->expects(self::once())->method('abandon');

        $pending = new PendingOperations();
        $pending->add($operation);
        $pending->abandon($operation);
        $pending->abandonAll();
    }

    #[Test]
    public function abandonAllEndsWhatIsStillPending(): void
    {
        $operation = $this->createMock(RunningOperation::class);
        $operation->expects(self::once())->method('abandon');

        $pending = new PendingOperations();
        $pending->add($operation);
        $pending->abandonAll();
        $pending->abandonAll();
    }
}
