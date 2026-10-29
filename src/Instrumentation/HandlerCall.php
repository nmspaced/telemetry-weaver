<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation;

use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryOperation;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Runs application code the kernel calls — a controller, an event listener — as an operation.
 *
 * An exception leaving it fails the span unless it is an HTTP exception below 500: a 4xx is the
 * client's failure, and the server span does not count it either.
 *
 * @internal
 */
final readonly class HandlerCall
{
    private const int SERVER_ERROR_STATUS = 500;

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     * @throws \Throwable
     */
    public static function run(BoundaryOperation $operation, \Closure $work): mixed
    {
        $running = $operation->start();

        try {
            return $work();
        } catch (\Throwable $throwable) {
            $running->finish(self::isFailure($throwable) ? $throwable : null);

            throw $throwable;
        } finally {
            $running->finish();
        }
    }

    private static function isFailure(\Throwable $throwable): bool
    {
        return (
            !$throwable instanceof HttpExceptionInterface
            || $throwable->getStatusCode() >= self::SERVER_ERROR_STATUS
        );
    }
}
