<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OwnedSpan;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/** An owner ended by its execution is marked, so its operation records no duration afterwards. */
#[CoversClass(OwnedSpan::class)]
final class OwnedSpanAbandonmentTest extends TelemetryTestCase
{
    #[Test]
    public function abandoningEndsTheSpanAndMarksItAbandoned(): void
    {
        $owner = $this->spans->open('operation', new SpanOptions());

        $owner->abandon();

        self::assertTrue($owner->isAbandoned());
        self::assertTrue($owner->isFinished());
        self::assertNull($this->contextStorage->scope());
        self::assertSame(['operation'], $this->exportedNames());
        $this->assertNoReports();
    }

    #[Test]
    public function abandoningAnOwnerThatAlreadyFinishedChangesNothing(): void
    {
        $owner = $this->spans->open('operation', new SpanOptions());
        $owner->finish();

        $owner->abandon();

        self::assertFalse($owner->isAbandoned(), 'the operation completed before its execution did');
        self::assertCount(1, $this->exported());
        $this->assertNoReports();
    }
}
