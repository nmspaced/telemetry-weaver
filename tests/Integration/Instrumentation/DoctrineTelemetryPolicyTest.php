<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\DoctrineTelemetryTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use PHPUnit\Framework\Attributes\Test;

/**
 * `only_with_parent` and the trace/metric signal switches. Statement/query span shape lives in
 * {@see DoctrineTelemetryTest}; transaction boundaries live in {@see
 * DoctrineTelemetryTransactionTest}.
 */
final class DoctrineTelemetryPolicyTest extends DoctrineTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function withoutAParentSpanOnlyTheMetricIsRecorded(): void
    {
        $connection = $this->connection(new DoctrinePolicy(onlyWithParent: true));
        $connection->executeQuery('SELECT id FROM users');

        $this->reader->collect();

        self::assertSame([], $this->exportedNames());
        self::assertSame(1, $this->histogram('db.client.operation.duration')->count);
    }

    /** @throws \Throwable */
    #[Test]
    public function withAParentSpanTheQuerySpanIsARealChild(): void
    {
        $connection = $this->connection(new DoctrinePolicy(onlyWithParent: true));

        $parent = $this->tracers->getTracer('test')->spanBuilder('parent')->startSpan();
        $scope = $parent->activate();

        try {
            $connection->executeQuery('SELECT id FROM users');
        } finally {
            $scope->detach();
            $parent->end();
        }

        $query = $this->exportedSpan();
        self::assertSame('SELECT users', $query->getName());
        self::assertSame($parent->getContext()->getSpanId(), $query->getParentContext()->getSpanId());
    }

    /** @throws \Throwable */
    #[Test]
    public function aNoopMeterLeavesTheSpanAndRecordsNothing(): void
    {
        $connection = $this->connection(meter: new NoopMeter());
        $connection->executeQuery('SELECT id FROM users');

        $this->reader->collect();

        self::assertSame(['SELECT users'], $this->exportedNames());
        self::assertSame([], $this->recordedMetricNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function aNoOpSpanOpenerLeavesTheMetricAndRecordsNoSpan(): void
    {
        $connection = $this->connection(spanOpener: NoOpSpanOpener::disabled());
        $connection->executeQuery('SELECT id FROM users');

        $this->reader->collect();

        self::assertSame([], $this->exportedNames());
        self::assertSame(1, $this->histogram('db.client.operation.duration')->count);
    }
}
