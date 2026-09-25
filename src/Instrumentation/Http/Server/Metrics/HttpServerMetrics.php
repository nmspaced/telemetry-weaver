<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Durations;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Measurement;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelationSource;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\SemConv\Incubating\Metrics\HttpIncubatingMetrics;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;

/**
 * The instruments for incoming HTTP requests.
 *
 * Request metrics work without tracing, so there is no owned span: the correlation is read
 * once per request from the ambient context and reused for all three instruments.
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
        private DurationRecorder $recorder,
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

    /** The trace this request's measurements belong to, while the server span is still current. */
    public function correlation(): ?TraceCorrelation
    {
        try {
            return $this->correlations->current();
        } catch (\Throwable $throwable) {
            $this->reporter->report('Trace correlation read failed', 'http_server', $throwable);

            return null;
        }
    }

    public function startDuration(?TraceCorrelation $correlation): Measurement
    {
        return Durations::start(
            $this->duration,
            $correlation,
            $this->reporter,
            HttpMetrics::HTTP_SERVER_REQUEST_DURATION,
        );
    }

    /**
     * @param array<non-empty-string, int|string> $attributes
     */
    public function recordRequestBodySize(int $bytes, array $attributes, ?TraceCorrelation $correlation): void
    {
        $this->recorder->record($this->requestBodySize, $bytes, $attributes, $correlation);
    }

    /**
     * @param array<non-empty-string, int|string> $attributes
     */
    public function recordResponseBodySize(int $bytes, array $attributes, ?TraceCorrelation $correlation): void
    {
        $this->recorder->record($this->responseBodySize, $bytes, $attributes, $correlation);
    }
}
