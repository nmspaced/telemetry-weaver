<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\ClientInstrumentation;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HostPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HttpClientTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\RequestPropagation;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\ResponseMetadata;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\TraceableHttpClient;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A real `TraceableHttpClient` recording into `InMemoryTelemetry`.
 *
 * @internal
 */
abstract class HttpClientTelemetryTestCase extends TestCase
{
    protected InMemoryTelemetry $telemetry;

    #[\Override]
    protected function setUp(): void
    {
        $this->telemetry = InMemoryTelemetry::create();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->telemetry->shutdown();
    }

    protected function client(MockHttpClient $delegate, ?HostPolicy $policy = null): TraceableHttpClient
    {
        $reporter = new InstrumentationFailureReporter(new NullLogger());

        return new TraceableHttpClient(
            $delegate,
            new ClientInstrumentation(
                new HttpClientTelemetry($this->telemetry, $policy ?? new HostPolicy(), new OtelDurationRecorder()),
                new RequestPropagation(
                    new OtelPropagation(TraceContextPropagator::getInstance(), Context::storage(), $reporter),
                    $reporter,
                ),
                new ResponseMetadata($reporter),
                $reporter,
            ),
        );
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
}
