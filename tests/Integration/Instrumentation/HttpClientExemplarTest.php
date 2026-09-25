<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HttpClientTelemetry;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpClientTelemetryTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryAssertions;
use OpenTelemetry\SDK\Metrics\Data\Exemplar;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Which trace an outgoing request's measurements are correlated with. All three instruments
 * describe one request and the conventions expect them to be joinable, so they have to name the
 * same trace as well as the same labels.
 */
#[CoversClass(HttpClientTelemetry::class)]
final class HttpClientExemplarTest extends HttpClientTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function everyMeasurementOfOneRequestNamesTheTraceItWasMadeIn(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('body', ['response_headers' => [
            'content-length' => '4',
        ]])));

        $first = $this->telemetry->operation('caller')->start();
        $response = $client->request('GET', 'https://example.org');
        $first->finish();

        $second = $this->telemetry->operation('somebody-else')->start();
        self::assertSame('body', $response->getContent());
        $second->finish();

        $metrics = $this->telemetry->measurements();

        $spans = [];
        foreach ($this->telemetry->spans() as $span) {
            $spans[$span->getName()] = $span->getContext()->getSpanId();
        }

        $clientSpan = $spans['GET'] ?? self::fail('the client span was not exported');
        $intruder = $spans['somebody-else'] ?? self::fail('the second operation was not exported');

        foreach (['http.client.request.duration', 'http.client.response.body.size'] as $name) {
            $point = HttpTelemetryAssertions::histogramPointNamedIn($metrics, $name);
            self::assertSame($clientSpan, self::exemplarSpanId($point), $name . ' names another trace');
            self::assertNotSame($intruder, self::exemplarSpanId($point), $name . ' names the reader of the body');
        }
    }

    private static function exemplarSpanId(HistogramDataPoint $point): ?string
    {
        $exemplars = [];

        foreach ($point->exemplars as $exemplar) {
            self::assertInstanceOf(Exemplar::class, $exemplar);
            $exemplars[] = $exemplar;
        }

        self::assertCount(1, $exemplars, 'the measurement carries exactly one exemplar');

        return ($exemplars[0] ?? self::fail('unreachable'))->spanId;
    }
}
