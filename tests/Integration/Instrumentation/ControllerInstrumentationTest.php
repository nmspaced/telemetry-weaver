<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Api\TraceContext;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerFunction;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\TraceableArgumentResolver;
use Nmspaced\TelemetryWeaver\Tests\Fake\OrderController;
use Nmspaced\TelemetryWeaver\Tests\Support\ControllerTelemetryTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Span shape for argument resolution and the controller call, and what the wrapping leaves intact
 * for the rest of the kernel.
 */
#[CoversClass(ControllerTelemetrySubscriber::class)]
#[CoversClass(TraceableArgumentResolver::class)]
#[CoversClass(ControllerTelemetry::class)]
#[CoversClass(ControllerFunction::class)]
final class ControllerInstrumentationTest extends ControllerTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function argumentsAndTheCallAreSiblingsUnderTheServerSpan(): void
    {
        $this->handle($this->orderRequest('show'));

        self::assertSame(['controller.arguments', 'OrderController::show', 'GET'], $this->exportedNames());

        $server = $this->exportedSpan(2);
        foreach ([$this->exportedSpan(0), $this->exportedSpan(1)] as $span) {
            self::assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
            self::assertSame($server->getContext()->getSpanId(), $span->getParentContext()->getSpanId());
            self::assertSame(OrderController::class . '::show', $span->getAttributes()->get('code.function.name'));
        }

        self::assertSame('arguments', $this->exportedSpan(0)->getAttributes()->get('controller.operation.name'));
        self::assertSame('call', $this->exportedSpan(1)->getAttributes()->get('controller.operation.name'));
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function workInsideTheControllerIsAChildOfTheCall(): void
    {
        $inside = null;

        $response = $this->handle($this->orderRequest('show', function () use (&$inside): void {
            $inside = $this->activeTrace();
        }));

        self::assertSame('7', $response->getContent());
        self::assertInstanceOf(TraceContext::class, $inside);
        self::assertSame($this->exportedSpan(1)->getContext()->getSpanId(), $inside->spanId);
    }

    /**
     * HttpKernel is not a strict_types file, so a string reaches an int parameter there; the
     * wrapper must not tighten that.
     *
     * @throws \Throwable
     */
    #[Test]
    public function argumentsAreCoercedAsWhenTheKernelCallsTheController(): void
    {
        $this->dispatcher->addListener(KernelEvents::CONTROLLER_ARGUMENTS, static function (ControllerArgumentsEvent $event): void {
            $event->setArguments(['42']);
        });

        $response = $this->handle($this->orderRequest('show'));

        self::assertSame('42', $response->getContent());
        self::assertSame('OrderController::show', $this->exportedSpan(1)->getName());
    }

    /** @throws \Throwable */
    #[Test]
    public function viewListenersStillSeeTheControllersNamedArgumentsAndAttributes(): void
    {
        $seen = null;
        $this->dispatcher->addListener(KernelEvents::VIEW, static function (ViewEvent $event) use (&$seen): void {
            $metadata = $event->controllerMetadata;
            self::assertNotNull($metadata);
            $seen = [
                'reflector' => $metadata->getReflector()->getName(),
                'arguments' => $metadata->getNamedArguments(),
                'cache' => \count($metadata->getAttributes(Cache::class)),
            ];
            $event->setResponse(new Response());
        });

        $this->handle($this->orderRequest('view'));

        self::assertSame(['reflector' => 'view', 'arguments' => ['id' => 7], 'cache' => 1], $seen);
        self::assertSame('OrderController::view', $this->exportedSpan(1)->getName());
    }

    /** @throws \Throwable */
    #[Test]
    public function aThrowingControllerFailsItsSpanAndIsPutBackForExceptionListeners(): void
    {
        $reflector = null;
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event) use (
            &$reflector,
        ): void {
            $reflector = $event->controllerMetadata?->getReflector()->getName();
            $event->setResponse(new Response('', 500));
        });

        $this->handle($this->orderRequest('fail'));

        $call = $this->span('OrderController::fail');
        self::assertSame('fail', $reflector);
        self::assertSame(StatusCode::STATUS_ERROR, $call->getStatus()->getCode());
        self::assertSame(\RuntimeException::class, $call->getAttributes()->get('error.type'));
        self::assertSame('exception', $this->firstEventName($call));
    }

    /** @throws \Throwable */
    #[Test]
    public function aClientErrorLeavesTheCallStatusUnsetLikeTheServerSpan(): void
    {
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event): void {
            $event->setResponse(new Response('', 404));
        });

        $this->handle($this->orderRequest('missing'));

        $call = $this->span('OrderController::missing');
        self::assertSame(StatusCode::STATUS_UNSET, $call->getStatus()->getCode());
        self::assertNull($call->getAttributes()->get('error.type'));
        self::assertSame([], $call->getEvents());
    }

    /** @throws \Throwable */
    #[Test]
    public function aSubRequestControllerNestsUnderTheCallThatRenderedIt(): void
    {
        $this->handle($this->request(
            /** @throws \Throwable */ function (): Response {
                $this->subRequest(static fn(): Response => new Response('fragment'));

                return new Response();
            },
        ));

        self::assertSame(
            [
                'controller.arguments',
                'controller.arguments',
                'ControllerInstrumentationTest::{closure}',
                'GET',
                'ControllerInstrumentationTest::{closure}',
                'GET',
            ],
            $this->exportedNames(),
        );
        self::assertSame(
            self::class . '::{closure}',
            $this->exportedSpan(2)->getAttributes()->get('code.function.name'),
        );

        $innerCall = $this->exportedSpan(2);
        $subServer = $this->exportedSpan(3);
        $outerCall = $this->exportedSpan(4);
        self::assertSame($subServer->getContext()->getSpanId(), $innerCall->getParentContext()->getSpanId());
        self::assertSame($outerCall->getContext()->getSpanId(), $subServer->getParentContext()->getSpanId());
    }

    /** @throws \Throwable */
    #[Test]
    public function outsideATraceNoSpanIsOpened(): void
    {
        $this->boot(excludedPaths: ['/orders']);
        $this->instrument();

        $response = $this->handle($this->orderRequest('show'));

        self::assertSame('7', $response->getContent());
        self::assertSame([], $this->exported());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function anInvokableControllerIsNamedAfterItsInvokeMethod(): void
    {
        $request = Request::create('/orders');
        $request->attributes->set('_controller', new OrderController());

        $this->handle($request);

        self::assertSame('OrderController::__invoke', $this->exportedSpan(1)->getName());
        self::assertSame(
            OrderController::class . '::__invoke',
            $this->exportedSpan(1)->getAttributes()->get('code.function.name'),
        );
    }
}
