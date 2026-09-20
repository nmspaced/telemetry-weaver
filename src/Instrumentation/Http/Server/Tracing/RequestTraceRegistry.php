<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionRegistry;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The open server spans of the current execution, keyed by their request.
 */
final readonly class RequestTraceRegistry implements ResetInterface
{
    /**
     * @var ExecutionRegistry<RequestTrace>
     */
    private ExecutionRegistry $traces;

    /**
     * @param int<400, 599> $recordExceptionMinStatus
     */
    public function __construct(
        private BoundaryTelemetry $telemetry,
        private InstrumentationFailureReporter $reporter,
        private int $recordExceptionMinStatus = 500,
    ) {
        $this->traces = new ExecutionRegistry($reporter, 'http.server traces');
    }

    /**
     * Null means the request goes untraced: a replaced boundary telemetry that throws
     * here must not turn a working request into a 500, which is the same reason the
     * metric side guards its own setup.
     *
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function open(
        Request $request,
        HttpMethod $method,
        array $attributes = [],
        SpanKind $kind = SpanKind::Internal,
        ?IncomingTrace $parent = null,
    ): ?RequestTrace {
        try {
            $operation = $this->telemetry
                ->boundary($method->spanName())
                ->attributes($attributes)
                ->kind($kind)
                ->from($parent);

            $name = $method->spanName();
            $minStatus = $this->recordExceptionMinStatus;

            return $this->traces->open(
                $request,
                static fn(): RequestTrace => new RequestTrace($operation->start(), $name, $minStatus),
            );
        } catch (\Throwable $throwable) {
            $this->reporter->report('HTTP trace setup failed', 'kernel.request', $throwable);

            return null;
        }
    }

    public function of(Request $request): ?RequestTrace
    {
        return $this->traces->of($request);
    }

    public function finish(Request $request): void
    {
        $this->traces->close($request);
    }

    public function route(Request $request, RequestPolicy $policy): void
    {
        $trace = $this->of($request);

        if ($trace === null) {
            return;
        }

        try {
            $trace->route($policy->routeTemplate($request));
        } catch (\Throwable $throwable) {
            $this->reporter->report('HTTP trace route resolution failed', 'http.route', $throwable);
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->traces->reset();
    }
}
