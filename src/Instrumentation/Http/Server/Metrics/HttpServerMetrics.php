<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Durations;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Measurement;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelationSource;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\SemConv\Incubating\Metrics\HttpIncubatingMetrics;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;

/**
 * The instruments for incoming HTTP requests.
 *
 * All three are unconditional: a MeterInterface always returns an instrument,
 * a no-op one when the meter itself is no-op. Making the fields nullable only
 * pushed an impossible state onto every caller.
 *
 * This is the one instrumentation that measures an interval without owning an operation,
 * and the reason is deliberate: request metrics are collected by a subscriber of their own
 * so that they survive tracing being switched off. There is therefore no span to take a
 * correlation from, and the source is asked for whatever the tracing subscriber — which
 * runs first — has already made current.
 */
final readonly class HttpServerMetrics
{
    private Duration $duration;

    private HistogramInterface $requestBodySize;

    private HistogramInterface $responseBodySize;

    public function __construct(
        Metrics $metrics,
        private TraceCorrelationSource $correlations,
        private InstrumentationFailureReporter $reporter,
        OperationBuckets $buckets = DefaultBuckets::Http,
    ) {
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
        return Durations::start(
            $this->duration,
            $this->correlations->current(),
            $this->reporter,
            HttpMetrics::HTTP_SERVER_REQUEST_DURATION,
        );
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
