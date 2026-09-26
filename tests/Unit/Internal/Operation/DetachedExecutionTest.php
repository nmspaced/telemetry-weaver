<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Between `detach()` and `finish()` an execution, like a request between `kernel.finish_request` and
 * `kernel.terminate`, lets lazy work resume and complete; only what is left unfinished is abandoned.
 */
final class DetachedExecutionTest extends PublicTelemetryTestCase
{
    #[Test]
    public function workReleasedByDetachStillFinishesNormallyBeforeTheExecutionEnds(): void
    {
        $telemetry = $this->telemetry();
        $execution = $telemetry->execution('request')->start();
        $lazy = $telemetry->boundary('cache.getItems')->duration($this->duration($telemetry))->start();

        $execution->detach();
        self::assertNull($this->contextStorage->scope(), 'kernel.finish_request leaves nothing active');
        $lazy->resume();
        self::assertSame($lazy->span()->spanId(), $this->activeTrace()?->spanId);
        $lazy->finish();
        $execution->finish();

        self::assertSame(['cache.getItems', 'request'], $this->exportedNames());
        self::assertSame(1, $this->metricPoint()->count, 'work that completed keeps its duration');
        self::assertNull($this->contextStorage->scope());
        $this->assertNoReports();
    }

    #[Test]
    public function workReleasedByDetachAndNeverResumedIsAbandonedWhenTheExecutionEnds(): void
    {
        $telemetry = $this->telemetry();
        $duration = $this->duration($telemetry);
        $execution = $telemetry->execution('request')->start();
        $telemetry->operation('done')->duration($duration)->start()->finish();
        $lazy = $telemetry->boundary('cache.getItems')->duration($duration)->start();

        $execution->detach();
        self::assertTrue($lazy->span()->isRecording());
        $execution->finish();
        $lazy->finish();

        self::assertSame(['done', 'cache.getItems', 'request'], $this->exportedNames());
        self::assertSame(1, $this->metricPoint()->count, 'nobody saw the unfinished work complete');
        $this->assertNoReports();
    }

    #[Test]
    public function aSuspendedOperationIsNotOnTheStackAndOutlivesTheExecution(): void
    {
        $telemetry = $this->telemetry();
        $execution = $telemetry->execution('request')->start();
        $lazy = $telemetry->boundary('cache.getItems')->start();
        $lazy->suspend();

        $execution->finish();

        self::assertTrue($lazy->span()->isRecording(), 'a lazy result may still be read later');
        $lazy->resume();
        $lazy->finish();
        self::assertSame(['request', 'cache.getItems'], $this->exportedNames());
        self::assertNull($this->contextStorage->scope());
        $this->assertNoReports();
    }
}
