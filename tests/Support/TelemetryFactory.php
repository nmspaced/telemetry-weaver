<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\Context\Context;
use Psr\Log\NullLogger;

final readonly class TelemetryFactory
{
    public static function create(
        MeterInterface $meter,
        SpanOpenerInterface $spans,
        InstrumentationFailureReporter $reporter,
        ClockInterface $clock = new SystemClock(),
    ): Telemetry {
        return new DefaultTelemetry($spans, new SafeMetrics($meter, $reporter, $clock), $reporter, Context::storage());
    }

    public static function tracing(
        SpanOpenerInterface $spans,
        ?InstrumentationFailureReporter $reporter = null,
    ): Telemetry {
        return self::create(new NoopMeter(), $spans, $reporter ?? new InstrumentationFailureReporter(new NullLogger()));
    }
}
