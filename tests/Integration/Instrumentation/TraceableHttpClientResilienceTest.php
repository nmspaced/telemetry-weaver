<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\ClientInstrumentation;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HostPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HttpClientTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\RequestPropagation;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\ResponseMetadata;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\TraceableHttpClient;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpClientTelemetryTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpHeaders;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryAssertions;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Span/measurement ownership under reset, cancellation and propagation failure, and
 * process-lifetime safety of the shared client. Core request/response behaviour lives in
 * {@see TraceableHttpClientTest}.
 */
final class TraceableHttpClientResilienceTest extends HttpClientTelemetryTestCase
{
    /**
     * @throws \Throwable
     */
    #[Test]
    public function resetAbandonsPendingWorkAndLateResponsesDoNotRecordItAgain(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok')));
        $response = $client->request('GET', 'https://example.org');
        $client->reset();
        self::assertCount(1, $this->telemetry->spans());
        self::assertSame('ok', $response->getContent());
        self::assertCount(1, $this->telemetry->spans());
        foreach ($this->telemetry->measurements() as $metric) {
            self::assertCount(0, MetricPoints::of($metric));
        }
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function completedAndCancelledResponsesDoNotAccumulateInTheSharedClient(): void
    {
        $client = $this->client(new MockHttpClient(static fn(): MockResponse => new MockResponse('ok')));
        $responses = new \WeakMap();
        for ($i = 0; $i < 500; ++$i) {
            $response = $client->request('GET', 'https://example.org');
            $responses[$response] = true;
            if (($i % 2) !== 0) {
                $response->cancel();

                unset($response);
                $this->telemetry->reset();

                continue;
            }

            $response->getContent();
            unset($response);
            $this->telemetry->reset();
        }

        \gc_collect_cycles();
        self::assertCount(0, $responses);
        self::assertFalse($this->telemetry->currentSpan()->context()->isValid());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function bodyFailuresAfterHeadersDoNotCreateAnotherCompletion(): void
    {
        $client = $this->client(new MockHttpClient(
            new MockResponse(
                (
                    /**
                     * @return \Generator<int, string>
                     *
                     * @throws TransportException
                     */
                    static function (): \Generator {
                        yield 'first';
                        throw new TransportException('body failed');
                    }
                )(),
            ),
        ));
        $response = $client->request('GET', 'https://example.org');
        self::assertSame(200, $response->getStatusCode());
        try {
            $response->getContent();
            self::fail('Body error must reach the caller');
        } catch (TransportExceptionInterface $transportException) {
            self::assertStringContainsString('body failed', $transportException->getMessage());
        }

        self::assertCount(1, $this->telemetry->spans());
        self::assertSame(StatusCode::STATUS_UNSET, $this->span()->getStatus()->getCode());
        self::assertSame(1, HttpTelemetryAssertions::firstHistogramPoint($this->telemetry)->count);
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function propagationFailuresDoNotPreventRequests(): void
    {
        $propagator = $this->createStub(TextMapPropagatorInterface::class);
        $propagator->method('inject')->willThrowException(new \RuntimeException('propagation failed'));
        $reporter = new InstrumentationFailureReporter(new NullLogger());
        $client = new TraceableHttpClient(
            new MockHttpClient(new MockResponse('ok')),
            new ClientInstrumentation(
                new HttpClientTelemetry($this->telemetry, new HostPolicy()),
                new RequestPropagation($propagator, $reporter),
                new ResponseMetadata($reporter),
                $reporter,
            ),
        );
        self::assertSame('ok', $client->request('GET', 'https://example.org', ['headers' => [
            'X-Test' => 'kept',
        ]])->getContent());
        self::assertCount(1, $this->telemetry->spans());
        self::assertSame(StatusCode::STATUS_UNSET, $this->span()->getStatus()->getCode());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function propagationReplacesStaleTracestateWithoutDroppingOtherHeaders(): void
    {
        /** @var array<string, list<string>> $headers */
        $headers = [];
        $client = $this->client(new MockHttpClient(
            /** @param array<array-key, mixed> $options */
            static function (string $_method, string $_url, array $options) use (&$headers): MockResponse {
                $headers = HttpHeaders::normalized($options);

                return new MockResponse('ok');
            },
        ));
        $client->request('GET', 'https://example.org', ['headers' => [
            'tracestate' => 'old=state',
            'X-Test' => 'kept',
        ]])->getContent();
        self::assertArrayNotHasKey('tracestate', $headers);
        self::assertArrayHasKey('traceparent', $headers);
        self::assertSame(['X-Test: kept'], HttpHeaders::values($headers, 'x-test'));
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function resettingAClientDoesNotAbandonAnIndependentClientsRequest(): void
    {
        $reporter = new InstrumentationFailureReporter(new NullLogger());
        $instrumentation = new ClientInstrumentation(
            new HttpClientTelemetry($this->telemetry, new HostPolicy()),
            new RequestPropagation(TraceContextPropagator::getInstance(), $reporter),
            new ResponseMetadata($reporter),
            $reporter,
        );
        $first = new TraceableHttpClient(new MockHttpClient(new MockResponse('one')), $instrumentation);
        $second = new TraceableHttpClient(new MockHttpClient(new MockResponse('two')), $instrumentation);
        $one = $first->request('GET', 'https://one.example.org');
        $two = $second->request('GET', 'https://two.example.org');
        $first->reset();
        self::assertSame('two', $two->getContent());
        $points = MetricPoints::of($this->measurement());
        self::assertCount(1, $points);
        $point = $points[0] ?? Assert::fail('missing data point');
        self::assertSame('two.example.org', $point->attributes->get('server.address'));
        $one->cancel();
    }
}
