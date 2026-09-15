<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionRegistry;
use OpenTelemetry\Context\ContextInterface;
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
        private Telemetry $telemetry,
        InstrumentationFailureReporter $reporter,
        private int $recordExceptionMinStatus = 500,
    ) {
        $this->traces = new ExecutionRegistry($reporter, 'http.server traces');
    }

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function open(
        Request $request,
        HttpMethod $method,
        array $attributes = [],
        SpanKind $kind = SpanKind::Internal,
        ?ContextInterface $parent = null,
    ): RequestTrace {
        $operation = $this->telemetry->operation($method->spanName())->attributes($attributes)->kind($kind);
        if ($parent !== null) {
            $operation = $operation->parent($parent);
        }

        $name = $method->spanName();
        $minStatus = $this->recordExceptionMinStatus;

        return $this->traces->open(
            $request,
            static fn(): RequestTrace => new RequestTrace($operation->start(), $name, $minStatus),
        );
    }

    public function of(Request $request): ?RequestTrace
    {
        return $this->traces->of($request);
    }

    public function finish(Request $request): void
    {
        $this->traces->close($request);
    }

    #[\Override]
    public function reset(): void
    {
        $this->traces->reset();
    }
}
