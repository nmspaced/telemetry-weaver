<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\HttpResponseStatus;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionEntry;
use Nmspaced\TelemetryWeaver\Internal\Operation\ScopedOperation;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outcome of a server span is decided once, at the end.
 *
 * An exception is not recorded when it is caught: at kernel.exception nobody knows yet
 * what the request will answer, and Symfony answers most exceptions with a response —
 * a NotFoundHttpException becomes a 404, which is not a failure of the server. So the
 * throwable is held until complete(), where the status is known and the two questions
 * can be answered separately: whether to record the exception event
 * (`record_exception_min_status`) and whether the span is errored (>= 500, per the
 * conventions and not configurable).
 *
 * Holding it is bounded by the execution: the entry is released at the end of the
 * request, either through complete() or through the registry's reset().
 */
final class RequestTrace implements ExecutionEntry
{
    private ?HttpResponseStatus $status = null;

    private ?string $route = null;

    private ?\Throwable $error = null;

    /**
     * @param non-empty-string $method the span's name before a route is known
     * @param int<400, 599> $recordExceptionMinStatus lowest status at which the exception event is recorded
     */
    public function __construct(
        private readonly ScopedOperation $operation,
        private readonly string $method,
        private readonly int $recordExceptionMinStatus = 500,
    ) {}

    /**
     * @param string|null $template null leaves the bare method, per semconv for unrouted requests
     */
    public function route(?string $template): void
    {
        if ($template === null || $template === $this->route) {
            return;
        }

        $this->route = $template;
        $method = $this->method;

        $this->operation->span()->attribute(HttpAttributes::HTTP_ROUTE, $template);
        $this->operation->span()->rename(\sprintf('%s %s', $method, $template));
    }

    /**
     * Enrichment from elsewhere in the request — who is logged in, say.
     *
     * The operation is not handed out: a caller that could reach it could also end it, and
     * this span belongs to the request, not to whoever is describing it.
     *
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function attributes(array $attributes): void
    {
        $this->operation->span()->attributes($attributes);
    }

    /**
     * Remembers the exception; what to do with it is decided at complete().
     *
     * The latest one wins. kernel.exception fires again when handling the first
     * exception throws, and the throwable that escaped last is the one that
     * determined what the client saw.
     */
    public function exception(\Throwable $e): void
    {
        $this->error = $e;
    }

    public function response(Response $response): void
    {
        $this->status = HttpResponseStatus::fromResponse($response);
    }

    /** Releases the context activation, leaving the span open. */
    public function detach(): void
    {
        $this->operation->detach();
    }

    /** Applies the observed outcome and releases the owned scope and span. */
    #[\Override]
    public function complete(): void
    {
        try {
            $this->applyOutcome();
        } finally {
            $this->operation->finish();
            $this->error = null;
            $this->status = null;
            $this->route = null;
        }
    }

    /**
     * A span is ended either way. There is no outcome to apply — nobody saw
     * the response — but leaving it open would leak the span and keep its
     * context scope activated for the rest of the process.
     */
    #[\Override]
    public function abandon(): void
    {
        $this->complete();
    }

    private function applyOutcome(): void
    {
        $status = $this->status?->code;

        if ($status === null) {
            $this->applyUnansweredOutcome();

            return;
        }

        $error = $status >= $this->recordExceptionMinStatus ? $this->error : null;

        $span = $this->operation->span();
        $span->attribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $status);
        if ($error !== null) {
            $span->recordException($error);
        }

        if ($status >= 500) {
            $span->fail((string) $status);
        }
    }

    /**
     * No response was ever seen. With an exception in hand that is a request that
     * failed without answering — the span has to say so, and the conventions put the
     * exception type in error.type when there is no status code to put there.
     *
     * Without an exception there is nothing to claim: a request abandoned by reset()
     * because the process called exit() did not fail, nobody saw it end.
     */
    private function applyUnansweredOutcome(): void
    {
        $error = $this->error;

        if ($error === null) {
            return;
        }

        $span = $this->operation->span();
        $span->recordException($error);
        $span->fail($error::class);
    }
}
