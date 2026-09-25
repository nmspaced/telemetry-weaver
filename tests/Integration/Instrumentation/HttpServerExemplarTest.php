<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetrics;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\RequestMeasurement;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpMetricsTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use OpenTelemetry\SDK\Metrics\Data\Exemplar;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/** Request metrics recorded at terminate still point their exemplars at the request's span. */
#[CoversClass(HttpServerMetrics::class)]
#[CoversClass(RequestMeasurement::class)]
final class HttpServerExemplarTest extends HttpMetricsTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theBodySizesNameTheRequestsOwnSpanRatherThanWhatOutlivedIt(): void
    {
        $request = $this->request(static fn(): Response => new Response('body', 200, ['Content-Length' => '4']));
        $this->handle($request, false);

        $intruder = $this->leak('left-behind-by-someone-else');
        $this->kernel->terminate($request, new Response('body', 200, ['Content-Length' => '4']));
        $intruder->end();

        $server = $this->exportedSpan()->getContext()->getSpanId();

        foreach (['http.server.request.duration', 'http.server.response.body.size'] as $name) {
            $point = MetricPoints::first($this->metric($name));
            self::assertInstanceOf(HistogramDataPoint::class, $point);
            self::assertSame($server, self::exemplarSpanId($point), $name . ' names another trace');
        }

        self::assertNotSame($server, $intruder->getContext()->getSpanId(), 'the two spans must differ');
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
