<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use Psr\Log\NullLogger;

final readonly class TelemetryFactory
{
    public static function create(
        MeterInterface $meter,
        SpanOpenerInterface $spans,
        InstrumentationFailureReporter $reporter,
        ClockInterface $clock = new SystemClock(),
    ): DefaultTelemetry {
        return new DefaultTelemetry(
            $spans,
            new SafeMetrics($meter, $reporter, new OtelDurationRecorder(), $clock),
            $reporter,
            new OtelBaggageReader(),
        );
    }

    public static function tracing(
        SpanOpenerInterface $spans,
        ?InstrumentationFailureReporter $reporter = null,
    ): DefaultTelemetry {
        return self::create(new NoopMeter(), $spans, $reporter ?? new InstrumentationFailureReporter(new NullLogger()));
    }
}
