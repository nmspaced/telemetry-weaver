<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\HttpResponseStatus;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionEntry;
use Nmspaced\TelemetryWeaver\Internal\Operation\ScopedOperation;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use Symfony\Component\HttpFoundation\Response;

// @mago-expect lint:too-many-methods — execution-entry lifecycle plus the observed outcome
/**
 * A server span whose outcome is decided once, in `complete()`.
 *
 * An exception is held until the response status is known, because Symfony turns most
 * exceptions into responses (a 404 is not a server failure). The span errors at >= 500; the
 * exception event is recorded from `record_exception_min_status`.
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
     * Adds attributes without handing out the operation, which only the request may end.
     *
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function attributes(array $attributes): void
    {
        $this->operation->span()->attributes($attributes);
    }

    /** Remembers the exception for `complete()`; the latest one wins. */
    public function exception(\Throwable $e): void
    {
        $this->error = $e;
    }

    public function response(Response $response): void
    {
        $this->status = HttpResponseStatus::fromResponse($response);
    }

    /** An exception reached the kernel and no listener answered it; no terminate will follow. */
    public function isUnansweredAfterException(): bool
    {
        return $this->status === null && $this->error !== null;
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

    /** Ends the span without a response, so it does not leak. */
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

        $span = $this->operation->span();
        $span->attribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $status);
        if ($this->error !== null && $status >= $this->recordExceptionMinStatus) {
            $span->recordException($this->error);
        }

        if ($status >= 500) {
            $span->fail((string) $status);
        }
    }

    /** Without a response, an exception errors the span with its class as `error.type`. */
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
