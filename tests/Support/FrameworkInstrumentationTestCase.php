<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A real `InMemoryTelemetry` and reporter for the mailer, console and scheduler adapter tests.
 *
 * @internal
 */
abstract class FrameworkInstrumentationTestCase extends TestCase
{
    protected InMemoryTelemetry $telemetry;

    protected InstrumentationFailureReporter $reporter;

    #[\Override]
    protected function setUp(): void
    {
        $this->telemetry = InMemoryTelemetry::create();
        $this->reporter = new InstrumentationFailureReporter(new NullLogger());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->telemetry->shutdown();
    }

    /** One exported span, failing the test if it is missing. */
    protected function span(int $index = 0): SpanDataInterface
    {
        return $this->telemetry->spans()[$index] ?? Assert::fail('no exported span at index ' . $index);
    }

    protected function measurement(int $index = 0): Metric
    {
        return $this->telemetry->measurements()[$index] ?? Assert::fail('no measurement at index ' . $index);
    }

    /**
     * @param list<Metric> $metrics
     *
     * @return list<array{attributes: array<array-key, mixed>}>
     */
    protected static function histogramPoints(array $metrics, string $name): array
    {
        $points = [];

        foreach ($metrics as $metric) {
            if ($metric->name !== $name) {
                continue;
            }

            foreach (MetricPoints::of($metric) as $point) {
                $points[] = ['attributes' => $point->attributes->toArray()];
            }
        }

        return $points;
    }
}
