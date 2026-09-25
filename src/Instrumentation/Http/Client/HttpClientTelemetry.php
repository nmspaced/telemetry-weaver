<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Operation\ScopedOperation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Incubating\Metrics\HttpIncubatingMetrics;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;

/**
 * What an outgoing request records, and when it counts as failed.
 *
 * Per the client conventions any status >= 400 is an error, with the status as `error.type`.
 * Host exclusions apply per signal; a host excluded from both gets no operation at all.
 */
final readonly class HttpClientTelemetry
{
    /** The lowest status the client conventions call an error. */
    private const int ERROR_STATUS = 400;

    private Duration $duration;

    private HistogramInterface $requestBodySize;

    private HistogramInterface $responseBodySize;

    /** The recorder correlates body sizes with the request's trace. */
    public function __construct(
        private BoundaryTelemetry $telemetry,
        private HostPolicy $policy,
        private DurationRecorder $recorder,
        OperationBuckets $buckets = DefaultBuckets::Http,
    ) {
        $metrics = $telemetry->metrics();
        $this->duration = $metrics->duration(
            HttpMetrics::HTTP_CLIENT_REQUEST_DURATION,
            $buckets->unit(),
            $buckets->boundaries(),
        );
        $this->requestBodySize = $metrics->histogram(
            HttpIncubatingMetrics::HTTP_CLIENT_REQUEST_BODY_SIZE,
            'By',
            'Size of HTTP client request bodies.',
        );
        $this->responseBodySize = $metrics->histogram(
            HttpIncubatingMetrics::HTTP_CLIENT_RESPONSE_BODY_SIZE,
            'By',
            'Size of HTTP client response bodies.',
        );
    }

    /**
     * @return ScopedOperation|null null when the host is excluded from both signals
     */
    public function start(OutgoingRequest $request): ?ScopedOperation
    {
        $trace = $this->policy->trace($request->host);
        $measure = $this->policy->measure($request->host);

        if (!$trace && !$measure) {
            return null;
        }

        $plan = $this->telemetry
            ->boundary($request->method->spanName())
            ->kind(SpanKind::Client)
            ->attributes($request->spanAttributes());

        if (!$trace) {
            $plan = $plan->withoutSpan();
        }

        if ($measure) {
            $plan = $plan->duration($this->duration, attributes: $request->metricAttributes());
        }

        return $plan->start();
    }

    /** Ends the operation at the response status; reading the body afterwards changes nothing. */
    public function complete(
        ClientCall $call,
        int $status,
        ClientBodySize $bodySize,
        ?string $protocolVersion = null,
    ): void {
        $operation = $call->operation;

        $outcome = [HttpAttributes::HTTP_RESPONSE_STATUS_CODE => $status];

        if ($protocolVersion !== null && $protocolVersion !== '') {
            $outcome[NetworkAttributes::NETWORK_PROTOCOL_VERSION] = $protocolVersion;
        }

        $operation->span()->attributes($outcome);

        if ($status >= self::ERROR_STATUS) {
            $type = (string) $status;
            $operation->span()->fail($type);
            $outcome[ErrorAttributes::ERROR_TYPE] = $type;
        }

        $operation->metricAttributes($outcome);

        $correlation = $operation->correlation();
        $operation->finish();

        if (!$this->policy->measure($call->request->host)) {
            return;
        }

        $this->recordBodySizes($call->request->metricAttributes() + $outcome, $bodySize, $correlation);
    }

    /**
     * @param array<non-empty-string, string|int> $attributes
     */
    private function recordBodySizes(array $attributes, ClientBodySize $bodySize, ?TraceCorrelation $correlation): void
    {
        if ($bodySize->request !== null) {
            $this->recorder->record($this->requestBodySize, $bodySize->request, $attributes, $correlation);
        }

        if ($bodySize->response !== null) {
            $this->recorder->record($this->responseBodySize, $bodySize->response, $attributes, $correlation);
        }
    }
}
