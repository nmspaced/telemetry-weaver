<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Testing;

use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

final class InMemoryTelemetryTest extends TelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function resetKeepsExistingInstrumentsButDiscardsPriorMeasurements(): void
    {
        $telemetry = InMemoryTelemetry::create();
        try {
            $counter = $telemetry->metrics()->counter('orders');
            $telemetry->trace('first', static function () use ($counter): void {
                $counter->add(2);
            });
            self::assertCount(1, $telemetry->spans());
            $telemetry->reset();
            self::assertSame([], $telemetry->spans());
            $counter->add(3);
            $measurements = $telemetry->measurements();
            $firstMetric = $measurements[0] ?? Assert::fail('no measurement recorded');
            $point = MetricPoints::first($firstMetric);
            Assert::assertInstanceOf(NumberDataPoint::class, $point);
            self::assertSame(3, $point->value);
            $second = $telemetry->measurements();
            self::assertSame([], $second === [] ? [] : MetricPoints::of($second[0]));
        } finally {
            $telemetry->shutdown();
        }
    }

    #[Test]
    public function anExecutionEndsWhatItsWorkLeftUnfinished(): void
    {
        $telemetry = InMemoryTelemetry::create();
        try {
            $execution = $telemetry->execution('request')->start();
            $held = $telemetry->operation('unfinished')->start();

            $execution->finish();

            self::assertFalse($held->span()->isRecording());
            self::assertNull($telemetry->activeTrace());
            self::assertSame(
                ['unfinished', 'request'],
                \array_map(static fn(SpanDataInterface $span): string => $span->getName(), $telemetry->spans()),
            );
        } finally {
            $telemetry->shutdown();
        }
    }

    #[Test]
    public function aScopeNeedsAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InMemoryTelemetry::create('');
    }
}
