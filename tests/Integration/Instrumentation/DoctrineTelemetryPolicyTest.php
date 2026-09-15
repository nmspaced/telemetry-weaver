<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\DoctrineTelemetryTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use PHPUnit\Framework\Attributes\Test;

/**
 * `only_with_parent` and the trace/metric signal switches. Statement/query span shape lives
 * in {@see DoctrineTelemetryTest}; transaction boundaries live in
 * {@see DoctrineTelemetryTransactionTest}.
 */
final class DoctrineTelemetryPolicyTest extends DoctrineTelemetryTestCase
{
    /**
     * The point of only_with_parent: an orphan query stays out of the traces, but the
     * load it puts on the database still has to be counted.
     *
     * @throws \Throwable
     */
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

    /**
     * Switching a signal off is an injected object, not a flag: a no-op meter records
     * nothing while the span keeps being produced.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aNoopMeterLeavesTheSpanAndRecordsNothing(): void
    {
        $connection = $this->connection(meter: new NoopMeter());
        $connection->executeQuery('SELECT id FROM users');

        $this->reader->collect();

        self::assertSame(['SELECT users'], $this->exportedNames());
        self::assertSame([], $this->recordedMetricNames());
    }

    /**
     * The mirror image: a no-op span opener silences the traces and leaves the metric.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aNoOpSpanOpenerLeavesTheMetricAndRecordsNoSpan(): void
    {
        $connection = $this->connection(spanOpener: new NoOpSpanOpener());
        $connection->executeQuery('SELECT id FROM users');

        $this->reader->collect();

        self::assertSame([], $this->exportedNames());
        self::assertSame(1, $this->histogram('db.client.operation.duration')->count);
    }
}
