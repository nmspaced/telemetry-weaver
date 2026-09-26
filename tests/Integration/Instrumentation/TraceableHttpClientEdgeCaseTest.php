<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HostPolicy;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpClientTelemetryTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryAssertions;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Requests the client instrumentation leaves alone, and sizes it can or cannot trust. */
final class TraceableHttpClientEdgeCaseTest extends HttpClientTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aHostExcludedFromBothSignalsIsNotObservedAtAll(): void
    {
        $client = $this->client(
            new MockHttpClient(new MockResponse('ok')),
            new HostPolicy(['internal.example.org'], ['internal.example.org']),
        );

        self::assertSame('ok', $client->request('GET', 'https://internal.example.org/health')->getContent());
        self::assertSame([], $this->telemetry->spans());
        self::assertSame(
            [],
            HttpTelemetryAssertions::pointsNamedIn($this->telemetry->measurements(), 'http.client.request.duration'),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aRelativeUrlTheInnerClientResolvesItselfIsNotObserved(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok'), 'https://api.example.org'));

        self::assertSame('ok', $client->request('GET', '/orders')->getContent());
        self::assertSame([], $this->telemetry->spans(), 'no host is known, so nothing honest can be recorded');
    }

    /** @throws \Throwable */
    #[Test]
    public function theUploadedSizeIsRecordedAsTheRequestBodySize(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok', ['size_upload' => 7.0])));

        $client->request('POST', 'https://api.example.org/orders', ['body' => 'payload'])->getContent();

        $size = HttpTelemetryAssertions::histogramPointNamedIn(
            $this->telemetry->measurements(),
            'http.client.request.body.size',
        );
        self::assertSame(7, (int) $size->sum);
    }

    /** @throws \Throwable */
    #[Test]
    public function aMalformedResponseLengthRecordsNoSize(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('ok', ['response_headers' => [
            'content-length' => 'about two',
        ]])));

        $client->request('GET', 'https://api.example.org/orders')->getContent();

        self::assertSame(
            [],
            HttpTelemetryAssertions::pointsNamedIn($this->telemetry->measurements(), 'http.client.response.body.size'),
        );
    }
}
