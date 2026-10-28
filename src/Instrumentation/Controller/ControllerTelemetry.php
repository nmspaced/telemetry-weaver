<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Controller;

use Nmspaced\TelemetryWeaver\Instrumentation\CodeFunction;
use Nmspaced\TelemetryWeaver\Instrumentation\HandlerCall;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryOperation;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;

/**
 * Spans argument resolution and the controller call, only inside an existing trace.
 *
 * @internal
 */
final readonly class ControllerTelemetry
{
    private const string OPERATION_NAME = 'controller.operation.name';

    public function __construct(
        private BoundaryTelemetry $telemetry,
    ) {}

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     * @throws \Throwable
     */
    public function arguments(CodeFunction $controller, \Closure $work): mixed
    {
        return $this
            ->operation('controller.arguments', $controller, 'arguments')
            ->run(static fn(): mixed => $work());
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     * @throws \Throwable
     */
    public function call(CodeFunction $controller, \Closure $work): mixed
    {
        return HandlerCall::run($this->operation($controller->spanName, $controller, 'call'), $work);
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $operation
     */
    private function operation(string $name, CodeFunction $controller, string $operation): BoundaryOperation
    {
        return $this->telemetry
            ->boundary($name)
            ->attributes([
                CodeAttributes::CODE_FUNCTION_NAME => $controller->name,
                self::OPERATION_NAME => $operation,
            ])
            ->onlyInsideTrace();
    }
}
