<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Operation\ScopedOperation;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Incubating\Metrics\HttpIncubatingMetrics;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;

/**
 * What an outgoing request records, and when it counts as failed.
 *
 * Immutable and process-scoped: the instrument is created once, and the ownership of an
 * individual request stays with the client that started it. Nothing here survives a
 * request.
 *
 * Any status at or above 400 is an error, which is where the client and the server
 * conventions deliberately disagree — the server adapter errors only from 500. A 404 is
 * a valid answer to give and a failure to receive: the server did what it was asked, the
 * caller did not get what it asked for. The status doubles as the error type because it
 * is a small bounded set and it is what an operator filters on.
 *
 * Both switches are honoured without a flag reaching this class. A host excluded from
 * traces still gets a measurement, a host excluded from metrics still gets a span, and a
 * host excluded from both produces no operation at all — the caller then knows there is
 * nothing to finish.
 */
final readonly class HttpClientTelemetry
{
    /** The lowest status the client conventions call an error. */
    private const int ERROR_STATUS = 400;

    private Duration $duration;

    private HistogramInterface $requestBodySize;

    private HistogramInterface $responseBodySize;

    public function __construct(
        private BoundaryTelemetry $telemetry,
        private HostPolicy $policy,
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
     * @return ScopedOperation|null null when the host is excluded from both signals,
     *                              so the caller does not have to track an operation
     *                              that would record nothing
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

    /**
     * The request reached a status. Everything after this — reading the body, a stream
     * that fails halfway — belongs to the caller and no longer changes this operation.
     */
    public function complete(
        ClientCall $call,
        int $status,
        ClientBodySize $bodySize,
        ?string $protocolVersion = null,
    ): void {
        $operation = $call->operation;

        $outcome = [HttpAttributes::HTTP_RESPONSE_STATUS_CODE => $status];

        // Known only once the response has started, bounded to a handful of values, and a
        // recommended attribute of both the span and the histogram — so it joins the
        // outcome rather than the labels frozen at start.
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
        $operation->finish();

        // Asked again rather than remembered from start(): the policy is immutable, so
        // the answer cannot have changed, and one source of truth cannot drift from
        // itself. A host excluded from metrics gets no duration and no body sizes —
        // recording two thirds of a joinable triple would be worse than recording none.
        if (!$this->policy->measure($call->request->host)) {
            return;
        }

        // After finish(), under the labels the duration was recorded with: the
        // conventions expect the three instruments to be joinable on one label set.
        $this->recordBodySizes($call->request->metricAttributes() + $outcome, $bodySize);
    }

    /**
     * @param array<non-empty-string, string|int> $attributes
     */
    private function recordBodySizes(array $attributes, ClientBodySize $bodySize): void
    {
        if ($bodySize->request !== null) {
            $this->requestBodySize->record($bodySize->request, $attributes);
        }

        if ($bodySize->response !== null) {
            $this->responseBodySize->record($bodySize->response, $attributes);
        }
    }
}
