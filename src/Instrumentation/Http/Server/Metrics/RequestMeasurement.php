<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\HttpResponseStatus;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionEntry;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Measurement;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use Symfony\Component\HttpFoundation\Response;

/**
 * One request's worth of HTTP server metrics.
 *
 * Duration and both body sizes share one attribute set, as the semantic
 * conventions require: the three describe the same request and have to be
 * joinable on the same labels.
 */
final class RequestMeasurement implements ExecutionEntry
{
    private ?HttpResponseStatus $status = null;

    private ?string $exceptionType = null;

    private ?int $responseBodySize = null;

    private bool $finished = false;

    private readonly Measurement $duration;

    /**
     * Captured once, at the start of the request, and used by all three instruments.
     *
     * Released as soon as the measurement ends: a correlation holds the context, which
     * holds the span, so a request that kept one would keep an SDK span reachable for as
     * long as the entry lived — and an abandoned entry would keep it for longer than that.
     */
    private ?TraceCorrelation $correlation;

    /**
     * @param array<non-empty-string, int|string> $attributes
     * @param int|null $requestBodySize null when the client declared no length
     */
    public function __construct(
        private readonly HttpServerMetrics $metrics,
        private array $attributes,
        private readonly ?int $requestBodySize = null,
    ) {
        $this->correlation = $metrics->correlation();
        $this->duration = $metrics->startDuration($this->correlation);
    }

    public function route(?string $route): void
    {
        if ($route === null || $route === '') {
            return;
        }

        $this->attributes[HttpAttributes::HTTP_ROUTE] = $route;
    }

    public function protocol(?string $protocol): void
    {
        $version = match ($protocol) {
            'HTTP/1.0' => '1.0',
            'HTTP/1.1' => '1.1',
            'HTTP/2', 'HTTP/2.0' => '2',
            'HTTP/3', 'HTTP/3.0' => '3',
            default => null,
        };

        if ($version !== null) {
            $this->attributes[NetworkAttributes::NETWORK_PROTOCOL_VERSION] = $version;
        }
    }

    public function exception(\Throwable $exception): void
    {
        $this->exceptionType = $exception::class;
    }

    public function response(Response $response): void
    {
        $this->status = HttpResponseStatus::fromResponse($response);
        $this->responseBodySize = HttpBodySize::ofResponse($response);
    }

    /**
     * The labels the three instruments are recorded under. Final only once
     * finish() has folded in the outcome.
     *
     * @return array<non-empty-string, int|string>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Folds in the outcome, freezes the attributes, then records all three
     * instruments under them.
     *
     * The freeze is what lets the body-size histograms reuse the attributes
     * the duration was recorded with: the semantic conventions expect the
     * three to be joinable on the same labels.
     */
    #[\Override]
    public function complete(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->attributes = $this->withOutcome();
        $this->duration->stop($this->attributes);
        $correlation = $this->correlation;
        $this->correlation = null;
        $this->recordBodySizes($correlation);
    }

    /**
     * Nothing is recorded. The request never reached an end, so its duration
     * is not a measurement of anything and would only distort the histogram.
     */
    #[\Override]
    public function abandon(): void
    {
        $this->finished = true;
        $this->correlation = null;
        $this->duration->cancel();
    }

    public function isUnfinishedAfterException(): bool
    {
        return $this->status === null && $this->exceptionType !== null;
    }

    /**
     * Only sizes that were actually observed. A body nobody declared the
     * length of is not a zero-byte body.
     */
    private function recordBodySizes(?TraceCorrelation $correlation): void
    {
        if ($this->requestBodySize !== null) {
            $this->metrics->recordRequestBodySize($this->requestBodySize, $this->attributes, $correlation);
        }

        if ($this->responseBodySize !== null) {
            $this->metrics->recordResponseBodySize($this->responseBodySize, $this->attributes, $correlation);
        }
    }

    /**
     * @return array<non-empty-string, int|string>
     */
    private function withOutcome(): array
    {
        $attributes = $this->attributes;

        if ($this->status !== null) {
            $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $this->status->code;

            $errorType = $this->status->errorType();

            if ($errorType !== null) {
                $attributes[ErrorAttributes::ERROR_TYPE] = $errorType;
            }

            return $attributes;
        }

        if ($this->exceptionType !== null) {
            $attributes[ErrorAttributes::ERROR_TYPE] = $this->exceptionType;
        }

        return $attributes;
    }
}
