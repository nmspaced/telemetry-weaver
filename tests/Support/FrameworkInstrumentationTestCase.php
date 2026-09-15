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
 * A real `InMemoryTelemetry` plus a reporter, shared by the mailer/console/scheduler
 * instrumentation adapter tests so each leaf class only carries the scenarios specific to it.
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

    /**
     * One exported span, asserted to exist. Indexing spans() directly turns a missing
     * span into a confusing type error further down instead of the assertion failure it
     * actually is.
     */
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
