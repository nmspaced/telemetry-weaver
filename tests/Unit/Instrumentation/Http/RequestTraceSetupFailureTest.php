<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Setting a span up is the one part of `kernel.request` that runs before the application, so it is
 * guarded the same way the metric side guards its own setup: a telemetry replacement that throws
 * leaves the request untraced instead of unanswered, and leaves nothing half-registered for the
 * next event to find.
 */
#[CoversClass(RequestTraceRegistry::class)]
final class RequestTraceSetupFailureTest extends TestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aThrowingBoundaryTelemetryCostsTheSpanAndNotTheRequest(): void
    {
        $reporter = new InstrumentationFailureReporter(new RecordingLogger());
        $telemetry = $this->createStub(BoundaryTelemetry::class);
        $telemetry->method('execution')->willThrowException(new \RuntimeException('telemetry unavailable'));
        $registry = new RequestTraceRegistry($telemetry, $reporter);
        $request = new Request();

        self::assertNull($registry->open($request, HttpMethod::from($request)));
        self::assertNull($registry->of($request), 'nothing half-registered survives the failure');

        $registry->reset();

        self::assertSame(1, $reporter->total(), 'reported once, not once per later event');
    }
}
