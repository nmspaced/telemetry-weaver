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

/**
 * Which trace a request's measurements are correlated with.
 *
 * The server span stops being ambient at `finish_request` and the metrics are recorded at
 * terminate, so by the time the body sizes are written the request's context is no longer
 * current. Recording them straight on the instrument left the exemplar to be resolved
 * against whatever was — in a worker, some unrelated span that outlived the request, and
 * in a request that ran inside one, the caller's. The correlation is captured once, when
 * the measurement starts, and all three instruments are written with it.
 */
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

        // Terminate runs after the request's activation is gone, under a span of its own.
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
