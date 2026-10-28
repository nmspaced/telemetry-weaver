<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Controller;

use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerFunction;
use Nmspaced\TelemetryWeaver\Tests\Fake\OrderController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** The names each shape of controller callable is reported under. */
#[CoversClass(ControllerFunction::class)]
final class ControllerFunctionTest extends TestCase
{
    /** @return iterable<string, array{callable, string, string}> */
    public static function controllers(): iterable
    {
        $class = OrderController::class;

        yield 'object and method' => [[new OrderController(), 'show'], $class . '::show', 'OrderController::show'];
        yield 'class and static method' => [[$class, 'index'], $class . '::index', 'OrderController::index'];
        yield 'Class::method string' => [$class . '::index', $class . '::index', 'OrderController::index'];
        yield 'invokable object' => [new OrderController(), $class . '::__invoke', 'OrderController::__invoke'];
        yield 'first-class callable' => [
            new OrderController()->show(...),
            $class . '::show',
            'OrderController::show',
        ];
        yield 'function name' => ['phpversion', 'phpversion', 'phpversion'];
        yield 'anonymous class' => [
            new class {
                public function __invoke(): Response
                {
                    return new Response();
                }
            },
            'class@anonymous::__invoke',
            'class@anonymous::__invoke',
        ];
    }

    #[Test]
    #[DataProvider('controllers')]
    public function eachShapeIsNamedByClassAndMethod(callable $controller, string $name, string $spanName): void
    {
        $function = ControllerFunction::of($controller);

        self::assertSame($name, $function->name);
        self::assertSame($spanName, $function->spanName);
    }

    #[Test]
    public function aClosureIsNamedAfterTheClassItWasDeclaredIn(): void
    {
        $function = ControllerFunction::of(static fn(): Response => new Response());

        self::assertSame(self::class . '::{closure}', $function->name);
        self::assertSame('ControllerFunctionTest::{closure}', $function->spanName);
    }
}
