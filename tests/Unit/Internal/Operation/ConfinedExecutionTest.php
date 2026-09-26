<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\API\Baggage\Baggage;
use PHPUnit\Framework\Attributes\Test;

final class ConfinedExecutionTest extends PublicTelemetryTestCase
{
    #[Test]
    public function unfinishedDurationsAreDiscardedEvenIfTheHandleIsFinishedLater(): void
    {
        $telemetry = $this->telemetry();
        $duration = $this->duration($telemetry);
        $execution = $telemetry->execution('request')->start();
        $done = $telemetry->operation('done')->duration($duration)->start();
        $done->finish();

        $held = $telemetry->operation('unfinished')->duration($duration)->start();

        $execution->finish();
        $held->finish();

        self::assertSame(['done', 'unfinished', 'request'], $this->exportedNames());
        self::assertSame(1, $this->metricPoint()->count);
        self::assertNull($this->contextStorage->scope());
        $this->assertNoReports();
    }

    #[Test]
    public function baggageOnlyOperationsAreReleasedWithoutAnyRecordedSpans(): void
    {
        $telemetry = $this->telemetry(traces: false);
        $execution = $telemetry->execution('request')->start();
        $held = $telemetry->operation('unfinished')->baggage(['tenant' => 'first'])->start();
        self::assertSame('first', Baggage::getCurrent()->getValue('tenant'));

        $execution->abandon();

        self::assertNull($this->contextStorage->scope());
        self::assertSame([], $held->baggage());
        self::assertSame([], $this->exported());
        $this->assertNoReports();
    }

    #[Test]
    public function aScopeActivatedDirectlyThroughOpenTelemetryIsReleasedToo(): void
    {
        $telemetry = $this->telemetry();
        $execution = $telemetry->execution('request')->start();
        $this->contextStorage->attach(
            Baggage::getBuilder()->set('tenant', 'first')->build()->storeInContext($this->contextStorage->current()),
        );

        $execution->finish();

        self::assertNull($this->contextStorage->scope());
        self::assertNull(Baggage::getCurrent()->getValue('tenant'));
        self::assertSame(['request'], $this->exportedNames());
        $this->assertNoReports();
    }

    #[Test]
    public function scopesActivatedBeforeTheExecutionStayActive(): void
    {
        $outer = $this->contextStorage->attach($this->contextStorage->current());
        $telemetry = $this->telemetry();
        $execution = $telemetry->execution('request')->start();
        $held = $telemetry->operation('unfinished')->baggage(['tenant' => 'first'])->start();

        $execution->finish();

        self::assertFalse($held->span()->isRecording());
        self::assertSame($outer, $this->contextStorage->scope());
        $outer->detach();
        self::assertSame(['unfinished', 'request'], $this->exportedNames());
        $this->assertNoReports();
    }

    #[Test]
    public function anOrdinaryBoundaryLeavesInnerOperationsAlone(): void
    {
        $telemetry = $this->telemetry();
        $boundary = $telemetry->boundary('cache.get')->start();
        $inner = $telemetry->operation('inner')->start();

        $boundary->finish();

        self::assertTrue($inner->span()->isRecording());
        $inner->finish();
        self::assertSame(['cache.get', 'inner'], $this->exportedNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function interleavedFiberExecutionsDoNotReleaseEachOthersOperations(): void
    {
        $this->useFiberBoundStorage();
        $telemetry = $this->telemetry();
        $run = /** @throws \Throwable */ static function (string $tenant) use ($telemetry): void {
            $execution = $telemetry->execution('request')->from(null)->start();
            $held = $telemetry->operation('unfinished')->baggage(['tenant' => $tenant])->start();
            \Fiber::suspend();
            self::assertSame($tenant, Baggage::getCurrent()->getValue('tenant'));
            self::assertTrue($held->span()->isRecording());
            $execution->finish();
            self::assertFalse($held->span()->isRecording());
            self::assertNull(Baggage::getCurrent()->getValue('tenant'));
        };
        $first = new \Fiber(
            /** @throws \Throwable */ static function () use ($run): void {
                $run('first');
            },
        );
        $second = new \Fiber(
            /** @throws \Throwable */ static function () use ($run): void {
                $run('second');
            },
        );
        $first->start();
        $second->start();
        $first->resume();
        $second->resume();

        self::assertSame(['unfinished', 'request', 'unfinished', 'request'], $this->exportedNames());
        self::assertNull($this->contextStorage->scope());
        $this->assertNoReports();
    }

    #[Test]
    public function anExecutionDoesNotKeepDroppedHandlesAlive(): void
    {
        $telemetry = $this->telemetry();
        $execution = $telemetry->execution('request')->start();
        $operation = $telemetry->operation('dropped')->start();
        $reference = \WeakReference::create($operation);
        unset($operation);

        self::assertNull($reference->get());
        $execution->finish();
        self::assertSame(['request'], $this->exportedNames());
        $this->assertNoReports();
    }
}
