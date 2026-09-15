<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\Measurement;
use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\HttpOperationBuckets;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\SemConv\Incubating\Metrics\HttpIncubatingMetrics;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;

/**
 * The instruments for incoming HTTP requests.
 *
 * All three are unconditional: a MeterInterface always returns an instrument,
 * a no-op one when the meter itself is no-op. Making the fields nullable only
 * pushed an impossible state onto every caller.
 */
final readonly class HttpServerMetrics
{
    private Duration $duration;

    private HistogramInterface $requestBodySize;

    private HistogramInterface $responseBodySize;

    public function __construct(Metrics $metrics, HttpOperationBuckets $buckets = new HttpOperationBuckets())
    {
        $this->duration = $metrics->duration(
            HttpMetrics::HTTP_SERVER_REQUEST_DURATION,
            $buckets->unit(),
            $buckets->boundaries(),
            'Duration of HTTP server requests.',
        );
        $this->requestBodySize = $metrics->histogram(
            HttpIncubatingMetrics::HTTP_SERVER_REQUEST_BODY_SIZE,
            'By',
            'Size of HTTP server request bodies.',
        );
        $this->responseBodySize = $metrics->histogram(
            HttpIncubatingMetrics::HTTP_SERVER_RESPONSE_BODY_SIZE,
            'By',
            'Size of HTTP server response bodies.',
        );
    }

    public function startDuration(): Measurement
    {
        return $this->duration->start();
    }

    /**
     * @param array<non-empty-string, int|string> $attributes
     */
    public function recordRequestBodySize(int $bytes, array $attributes): void
    {
        $this->requestBodySize->record($bytes, $attributes);
    }

    /**
     * @param array<non-empty-string, int|string> $attributes
     */
    public function recordResponseBodySize(int $bytes, array $attributes): void
    {
        $this->responseBodySize->record($bytes, $attributes);
    }
}
