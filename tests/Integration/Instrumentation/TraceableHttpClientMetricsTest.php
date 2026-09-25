<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HostPolicy;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpClientTelemetryTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryAssertions;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Response labelling and the metric shapes recorded for it: the frozen label set, method
 * normalization, body sizes and negotiated protocol. Request lifecycle and propagation live in
 * {@see TraceableHttpClientTest} and {@see TraceableHttpClientResilienceTest}.
 */
final class TraceableHttpClientMetricsTest extends HttpClientTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function exclusionsAreIndependentPerSignal(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok')), new HostPolicy(['*.example.org'], []));
        $client->request('GET', 'https://API.example.org')->getContent();
        self::assertSame([], $this->telemetry->spans());
        self::assertCount(1, HttpTelemetryAssertions::pointsNamed($this->telemetry, 'http.client.request.duration'));
        $client = $this->client(new MockHttpClient(new MockResponse('ok')), new HostPolicy([], ['api.example.org']));
        $client->request('GET', 'https://api.example.org')->getContent();
        self::assertCount(1, $this->telemetry->spans());
        foreach ($this->telemetry->measurements() as $metric) {
            self::assertCount(0, MetricPoints::of($metric));
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function theFrozenLabelSetCarriesEveryRequiredAttributeAndNothingUnbounded(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok')));
        $client->request('PATCH', 'https://API.Example.org:8443/orders/4711?token=secret')->getContent();

        $span = $this->span()->getAttributes()->toArray();
        self::assertSame('PATCH', HttpTelemetryAssertions::attribute($span, 'http.request.method'));
        self::assertSame('api.example.org', HttpTelemetryAssertions::attribute($span, 'server.address'));
        self::assertSame(8443, HttpTelemetryAssertions::attribute($span, 'server.port'));
        self::assertSame('https', HttpTelemetryAssertions::attribute($span, 'url.scheme'));
        self::assertSame('https://api.example.org:8443/orders/4711?token=REDACTED', HttpTelemetryAssertions::attribute(
            $span,
            'url.full',
        ));

        $point = HttpTelemetryAssertions::firstHistogramPoint($this->telemetry);
        self::assertSame(
            [
                'http.request.method' => 'PATCH',
                'server.address' => 'api.example.org',
                'server.port' => 8443,
                'url.scheme' => 'https',
                'http.response.status_code' => 200,
            ],
            $point->attributes->toArray(),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnknownMethodIsNormalizedAndTheDefaultPortIsImplied(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok')));
        $client->request('PROPFIND', 'http://example.org/')->getContent();

        $attributes = $this->span()->getAttributes()->toArray();
        self::assertSame('HTTP', $this->span()->getName());
        self::assertSame('_OTHER', HttpTelemetryAssertions::attribute($attributes, 'http.request.method'));
        self::assertSame('PROPFIND', HttpTelemetryAssertions::attribute($attributes, 'http.request.method_original'));
        self::assertSame(80, HttpTelemetryAssertions::attribute($attributes, 'server.port'));
        self::assertSame('http', HttpTelemetryAssertions::attribute($attributes, 'url.scheme'));
    }

    /** @throws \Throwable */
    #[Test]
    public function bodySizesAreRecordedUnderTheSameLabelsAsTheDuration(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{"ok":true}', ['response_headers' => [
            'content-length' => '11',
        ]])));
        $client->request('POST', 'https://api.example.org/orders', ['body' => 'payload'])->getContent();

        $measurements = $this->telemetry->measurements();
        self::assertCount(1, HttpTelemetryAssertions::pointsNamedIn($measurements, 'http.client.request.duration'));
        self::assertCount(1, HttpTelemetryAssertions::pointsNamedIn($measurements, 'http.client.response.body.size'));
        $duration = HttpTelemetryAssertions::histogramPointNamedIn($measurements, 'http.client.request.duration');
        $response = HttpTelemetryAssertions::histogramPointNamedIn($measurements, 'http.client.response.body.size');
        self::assertSame(11, (int) $response->sum);
        self::assertSame(
            $duration->attributes->toArray(),
            $response->attributes->toArray(),
            'the conventions expect duration and body sizes to be joinable on one label set',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aResponseWithoutADeclaredLengthRecordsNoSizeRatherThanZero(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('chunked body')));
        $client->request('GET', 'https://api.example.org/stream')->getContent();

        $measurements = $this->telemetry->measurements();
        self::assertCount(1, HttpTelemetryAssertions::pointsNamedIn($measurements, 'http.client.request.duration'));
        self::assertSame([], HttpTelemetryAssertions::pointsNamedIn($measurements, 'http.client.response.body.size'));
    }

    /** @throws \Throwable */
    #[Test]
    public function theNegotiatedProtocolIsReadFromTheStatusLine(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok', [
            'response_headers' => ['HTTP/1.1 200 OK', 'content-type: text/plain'],
        ])));
        $client->request('GET', 'https://api.example.org/orders')->getContent();

        self::assertSame('1.1', $this->span()->getAttributes()->get('network.protocol.version'));
        self::assertSame(
            '1.1',
            HttpTelemetryAssertions::histogramPointNamed(
                $this->telemetry,
                'http.client.request.duration',
            )->attributes->get('network.protocol.version'),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aMinorZeroOnMajorVersionsIsNormalised(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok', ['response_headers' => [
            'HTTP/2.0 200 OK',
        ]])));
        $client->request('GET', 'https://api.example.org/orders')->getContent();

        self::assertSame('2', $this->span()->getAttributes()->get('network.protocol.version'));
    }

    /** @throws \Throwable */
    #[Test]
    public function withoutAStatusLineNoProtocolIsReported(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok')));
        $client->request('GET', 'https://api.example.org/orders')->getContent();

        self::assertNull($this->span()->getAttributes()->get('network.protocol.version'));
        self::assertNull(HttpTelemetryAssertions::firstHistogramPoint($this->telemetry)->attributes->get(
            'network.protocol.version',
        ));
    }
}
