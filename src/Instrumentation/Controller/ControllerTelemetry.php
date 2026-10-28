<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Controller;

use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryOperation;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Spans argument resolution and the controller call, only inside an existing trace.
 *
 * @internal
 */
final readonly class ControllerTelemetry
{
    private const string OPERATION_NAME = 'controller.operation.name';

    /** The server span's threshold: a 4xx is the client's failure, not the controller's. */
    private const int SERVER_ERROR_STATUS = 500;

    public function __construct(
        private BoundaryTelemetry $telemetry,
    ) {}

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     * @throws \Throwable
     */
    public function arguments(ControllerFunction $controller, \Closure $work): mixed
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
    public function call(ControllerFunction $controller, \Closure $work): mixed
    {
        $operation = $this->operation($controller->spanName, $controller, 'call')->start();

        try {
            return $work();
        } catch (\Throwable $throwable) {
            $operation->finish(self::isFailure($throwable) ? $throwable : null);

            throw $throwable;
        } finally {
            $operation->finish();
        }
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $operation
     */
    private function operation(string $name, ControllerFunction $controller, string $operation): BoundaryOperation
    {
        return $this->telemetry
            ->boundary($name)
            ->attributes([
                CodeAttributes::CODE_FUNCTION_NAME => $controller->name,
                self::OPERATION_NAME => $operation,
            ])
            ->onlyInsideTrace();
    }

    private static function isFailure(\Throwable $throwable): bool
    {
        return (
            !$throwable instanceof HttpExceptionInterface
            || $throwable->getStatusCode() >= self::SERVER_ERROR_STATUS
        );
    }
}
