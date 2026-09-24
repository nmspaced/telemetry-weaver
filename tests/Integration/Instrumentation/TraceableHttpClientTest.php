<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Tests\Support\HttpClientTelemetryTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpHeaders;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Core request/response lifecycle: propagation into headers, span parenting, and transport
 * failure identity. Span/measurement ownership under reset and cancellation lives in
 * {@see TraceableHttpClientResilienceTest}; response labelling and metric shapes live in
 * {@see TraceableHttpClientMetricsTest}.
 */
final class TraceableHttpClientTest extends HttpClientTelemetryTestCase
{
    /**
     * @throws \Throwable
     */
    #[Test]
    public function lazyResponsesPropagateAChildAndRestoreTheParentImmediately(): void
    {
        $headers = [];
        $bodyRead = false;
        $client = $this->client(new MockHttpClient(
            /** @param array<array-key, mixed> $options */
            static function (string $_method, string $_url, array $options) use (&$headers, &$bodyRead): MockResponse {
                $headers = HttpHeaders::normalized($options);

                return new MockResponse(
                    (
                        /** @return \Generator<int, string> */
                        static function () use (&$bodyRead): \Generator {
                            $bodyRead = true;
                            yield 'hello';
                            yield ' world';
                        }
                    )(),
                );
            },
        ));
        $parent = $this->telemetry->operation('parent')->start();
        $parentId = $parent->span()->spanId();
        $response = $client->request(
            'GET',
            'https://user:secret@example.org/orders?token=secret#private',
            ['headers' => ['X-Test: keep', 'TraceParent: stale']],
        );
        self::assertFalse($bodyRead);
        self::assertSame([], $this->telemetry->spans());
        self::assertSame($parentId, $this->telemetry->activeTrace()?->spanId);
        self::assertSame(['X-Test: keep'], HttpHeaders::values($headers, 'x-test'));
        self::assertCount(1, HttpHeaders::values($headers, 'traceparent'));
        self::assertStringNotContainsString('stale', HttpHeaders::value($headers, 'traceparent'));
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->telemetry->spans());
        $span = $this->span();
        self::assertSame($parentId, $span->getParentContext()->getSpanId());
        self::assertStringContainsString($span->getContext()->getSpanId(), HttpHeaders::value($headers, 'traceparent'));
        self::assertSame('https://example.org/orders?token=REDACTED', $span->getAttributes()->get('url.full'));
        self::assertSame('hello world', $response->getContent());
        self::assertCount(1, $this->telemetry->spans());
        $parent->finish();
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function clientErrorsAreRecordedEvenWhenContentIsNotRead(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('missing', ['http_code' => 404])));
        $response = $client->request('GET', 'https://example.org/missing');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('missing', $response->getContent(false));
        $span = $this->span();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('404', $span->getAttributes()->get('error.type'));
        $point = MetricPoints::first($this->measurement());
        self::assertSame('404', $point->attributes->get('error.type'));
        self::assertSame(404, $point->attributes->get('http.response.status_code'));
        self::assertNull($point->attributes->get('url.full'));
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function cancelBeforeHeadersAbandonsTheMeasurement(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('body')));
        $response = $client->request('GET', 'https://example.org');
        $response->cancel();
        self::assertTrue($response->getInfo('canceled'));
        self::assertCount(1, $this->telemetry->spans());
        foreach ($this->telemetry->measurements() as $metric) {
            self::assertCount(0, MetricPoints::of($metric));
        }

        self::assertNull($this->telemetry->activeTrace());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function synchronousTransportErrorsKeepTheirIdentity(): void
    {
        $error = new TransportException('offline');
        $client = $this->client(new MockHttpClient(
            /** @throws TransportException */
            static function () use ($error): never {
                throw $error;
            },
        ));
        try {
            $client->request('GET', 'https://example.org');
            self::fail('Must throw');
        } catch (TransportExceptionInterface $transportException) {
            self::assertSame($error, $transportException);
        }

        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
        self::assertNull($this->telemetry->activeTrace());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function asyncTransportFailuresRemainObservable(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('', ['error' => 'network unavailable'])));
        $response = $client->request('GET', 'https://example.org');
        try {
            $response->getStatusCode();
            self::fail('Must throw');
        } catch (TransportExceptionInterface $transportException) {
            self::assertStringContainsString('network unavailable', $transportException->getMessage());
        }

        self::assertCount(1, $this->telemetry->spans());
        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function streamingPreservesChunksAndConcurrentRequestsHaveSiblingParents(): void
    {
        $client = $this->client(new MockHttpClient([
            new MockResponse(
                (
                    /** @return \Generator<int, string> */
                    static function (): \Generator {
                        yield 'one';
                        yield '';
                        yield 'two';
                    }
                )(),
            ),
            new MockResponse('three'),
        ]));
        $parent = $this->telemetry->operation('parent')->start();
        $responses = [
            $client->request('GET', 'https://example.org/1'),
            $client->request('GET', 'https://example.org/2'),
        ];
        $content = '';
        foreach ($client->stream($responses) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }

            $content .= $chunk->getContent();
        }

        self::assertStringContainsString('one', $content);
        self::assertSame(
            'onetwo',
            $responses[0]->getContent(),
            'A streaming timeout can be resumed without losing body chunks.',
        );
        self::assertStringContainsString('three', $content);
        self::assertCount(2, $this->telemetry->spans());
        foreach ($this->telemetry->spans() as $span) {
            self::assertSame($parent->span()->spanId(), $span->getParentContext()->getSpanId());
        }

        $parent->finish();
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function withOptionsKeepsDefaultsAndResolvesRelativeUrls(): void
    {
        /** @var list<array{0: string, 1: array<string, list<string>>}> $requests */
        $requests = [];
        $client = $this->client(new MockHttpClient(
            /** @param array<array-key, mixed> $options */
            static function (string $_method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$url, HttpHeaders::normalized($options)];

                return new MockResponse('ok');
            },
        ));
        $scoped = $client->withOptions([
            'base_uri' => 'https://api.example.org/v1/',
            'headers' => ['Authorization' => 'Bearer secret', 'X-Default' => 'yes'],
        ]);
        self::assertSame('ok', $scoped->request('GET', 'users')->getContent());
        self::assertSame('ok', $client->request('GET', 'https://other.example.org')->getContent());
        [$firstUrl, $firstHeaders] = $requests[0] ?? Assert::fail('missing first request');
        [, $secondHeaders] = $requests[1] ?? Assert::fail('missing second request');
        self::assertSame('https://api.example.org/v1/users', $firstUrl);
        self::assertSame(['Authorization: Bearer secret'], HttpHeaders::values($firstHeaders, 'authorization'));
        self::assertArrayNotHasKey('authorization', $secondHeaders);
        self::assertSame('api.example.org', $this->span()->getAttributes()->get('server.address'));
        self::assertSame('https://api.example.org/v1/users', $this->span()->getAttributes()->get('url.full'));
    }
}
