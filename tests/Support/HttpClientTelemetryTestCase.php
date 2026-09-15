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
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A real `TraceableHttpClient` wired to the production instrumentation, recording into
 * `InMemoryTelemetry`. Shared by the client instrumentation test classes so each one only
 * carries the scenarios specific to it. Reading recorded spans/metrics beyond a plain index
 * lookup lives in {@see HttpTelemetryAssertions}; header parsing lives in {@see HttpHeaders}.
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
                new HttpClientTelemetry($this->telemetry, $policy ?? new HostPolicy()),
                new RequestPropagation(TraceContextPropagator::getInstance(), $reporter),
                new ResponseMetadata($reporter),
                $reporter,
            ),
        );
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
}
