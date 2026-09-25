<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ExportBacklog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportBacklog::class)]
final class ExportBacklogTest extends TestCase
{
    #[Test]
    public function aFullBatchIsReadyOnceEnoughRecordsAreQueued(): void
    {
        $backlog = new ExportBacklog(batchSize: 3, capacity: 10);
        $backlog->added();
        $backlog->added();

        self::assertFalse($backlog->holdsFullBatch());

        $backlog->added();

        self::assertTrue($backlog->holdsFullBatch());
    }

    #[Test]
    public function aDrainedQueueStartsOver(): void
    {
        $backlog = new ExportBacklog(batchSize: 1, capacity: 10);
        $backlog->added();
        $backlog->completed(1);

        self::assertFalse($backlog->holdsFullBatch());
    }

    #[Test]
    public function recordsPastCapacityAreCountedAsDroppedUntilTaken(): void
    {
        $backlog = new ExportBacklog(batchSize: 2, capacity: 2);

        for ($i = 0; $i < 5; ++$i) {
            $backlog->added();
        }

        $backlog->completed(2);

        self::assertSame(3, $backlog->takeDropped(), 'a flush empties the queue, not the loss');
        self::assertSame(0, $backlog->takeDropped());
    }

    #[Test]
    public function anUntrackedQueueNeverHoldsAFullBatch(): void
    {
        $backlog = new ExportBacklog();

        for ($i = 0; $i < 10_000; ++$i) {
            $backlog->added();
        }

        self::assertFalse($backlog->holdsFullBatch());
        self::assertSame(0, $backlog->takeDropped());
    }

    #[Test]
    public function sizesFollowTheBatchSpanProcessorVariables(): void
    {
        $_SERVER['OTEL_BSP_MAX_EXPORT_BATCH_SIZE'] = '7';
        $_SERVER['OTEL_BSP_MAX_QUEUE_SIZE'] = '70';

        try {
            $backlog = ExportBacklog::spans();
        } finally {
            unset($_SERVER['OTEL_BSP_MAX_EXPORT_BATCH_SIZE'], $_SERVER['OTEL_BSP_MAX_QUEUE_SIZE']);
        }

        self::assertSame(7, $backlog->batchSize);
        self::assertSame(70, $backlog->capacity);
    }

    #[Test]
    public function sizesFollowTheBatchLogRecordProcessorVariables(): void
    {
        $_SERVER['OTEL_BLRP_MAX_EXPORT_BATCH_SIZE'] = '5';
        $_SERVER['OTEL_BLRP_MAX_QUEUE_SIZE'] = '50';

        try {
            $backlog = ExportBacklog::logRecords();
        } finally {
            unset($_SERVER['OTEL_BLRP_MAX_EXPORT_BATCH_SIZE'], $_SERVER['OTEL_BLRP_MAX_QUEUE_SIZE']);
        }

        self::assertSame(5, $backlog->batchSize);
        self::assertSame(50, $backlog->capacity);
    }
}
