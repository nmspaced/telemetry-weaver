<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use OpenTelemetry\API\Baggage\Baggage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class HttpManualOperationCleanupTest extends HttpTelemetryTestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function recording(): iterable
    {
        yield 'recording request' => [true, false];
        yield 'server tracing disabled' => [false, false];
        yield 'excluded request' => [true, true];
        yield 'excluded request with tracing disabled' => [false, true];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('recording')]
    public function retainedChildCannotCarryBaggageIntoTheNextRequest(bool $traced, bool $excluded): void
    {
        $this->serverSpans = $traced ? $this->spans : $this->spans->suppressed();
        $this->boot(excludedPaths: $excluded ? ['/health', '/orders'] : ['/health']);
        // A different facade from the server's must join the same execution.
        $telemetry = TelemetryFactory::tracing(
            new SpanOpener($this->provider->getTracer('application'), $this->contextStorage, $this->reporter),
            $this->reporter,
        );
        $held = null;
        $request = $this->request(static function () use ($telemetry, &$held): Response {
            $held = $telemetry->operation('unfinished')->baggage(['tenant' => 'first'])->start();

            return new Response();
        });
        $response = $this->kernel->handle($request);

        self::assertInstanceOf(RunningOperation::class, $held);
        self::assertNull($this->contextStorage->scope(), 'finish_request detaches child activations before the server');
        self::assertNull(Baggage::getCurrent()->getValue('tenant'));
        self::assertTrue($held->span()->isRecording(), 'detaching does not prematurely finish lazy work');

        $this->kernel->terminate($request, $response);

        self::assertFalse($held->span()->isRecording());
        self::assertSame([], $held->baggage());
        $held->finish();
        self::assertSame($traced && !$excluded ? ['unfinished', 'GET'] : ['unfinished'], $this->exportedNames());
        $this->handle($this->request(function (): Response {
            self::assertNull($this->activeTrace());
            self::assertNull(Baggage::getCurrent()->getValue('tenant'));

            return new Response();
        }, uri: '/health'));
        self::assertNull($this->contextStorage->scope());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function aScopeLeftActiveThroughOpenTelemetryIsReleasedWithTheRequest(): void
    {
        $this->handle($this->request(function (): Response {
            $raw = $this->tracer->spanBuilder('raw')->startSpan()->storeInContext($this->contextStorage->current());
            $this->contextStorage->attach(Baggage::getBuilder()->set('tenant', 'first')->build()->storeInContext($raw));

            return new Response();
        }));

        self::assertNull($this->contextStorage->scope());
        $this->handle($this->request(static function (): Response {
            self::assertNull(Baggage::getCurrent()->getValue('tenant'));

            return new Response();
        }));
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function anExcludedRequestDoesNotContinueTheCallersTrace(): void
    {
        $this->boot(excludedPaths: ['/health']);
        $telemetry = TelemetryFactory::tracing($this->spans, $this->reporter);
        $this->handle($this->request(
            /** @throws \Throwable */
            function () use ($telemetry): Response {
                self::assertNull($this->activeTrace());
                $telemetry->operation('check')->run(static fn(): null => null);

                return new Response();
            },
            uri: '/health',
            headers: ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
        ));

        self::assertSame(['check'], $this->exportedNames());
        self::assertNotSame('0af7651916cd43dd8448eb211c80319c', $this->exportedSpan()->getContext()->getTraceId());
        self::assertNull($this->contextStorage->scope());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function resetAbandonsChildrenInnermostFirstAndPreservesAnExternalScope(): void
    {
        $external = $this->tracer->spanBuilder('external')->startSpan();
        $scope = $external->activate();
        $baseline = $this->contextStorage->scope();
        try {
            $request = $this->request(static fn(): Response => new Response());
            $this->dispatcher->dispatch(
                new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST),
                KernelEvents::REQUEST,
            );
            $telemetry = TelemetryFactory::tracing($this->spans, $this->reporter);
            $first = $telemetry->operation('first')->start();
            $second = $telemetry->operation('second')->baggage(['tenant' => 'first'])->start();

            $this->scopes->reset();

            self::assertSame(['second', 'first', 'GET'], $this->exportedNames());
            self::assertFalse($first->span()->isRecording());
            self::assertFalse($second->span()->isRecording());
            self::assertTrue($external->isRecording());
            self::assertSame($baseline, $this->contextStorage->scope());
            self::assertNull(Baggage::getCurrent()->getValue('tenant'));
            $this->assertNoReports();
        } finally {
            $scope->detach();
            $external->end();
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnansweredExceptionAbandonsTheRetainedChild(): void
    {
        $telemetry = TelemetryFactory::tracing($this->spans, $this->reporter);
        $held = null;
        $error = new \RuntimeException('business failure');
        try {
            $this->kernel->handle($this->request(
                /** @throws \RuntimeException */
                static function () use ($telemetry, &$held, $error): never {
                    $held = $telemetry->operation('unfinished')->baggage(['tenant' => 'first'])->start();

                    throw $error;
                },
            ));
            self::fail('The business exception must escape');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($error, $runtimeException);
        }

        self::assertInstanceOf(RunningOperation::class, $held);
        self::assertFalse($held->span()->isRecording());
        self::assertNull($this->contextStorage->scope());
        self::assertSame(['unfinished', 'GET'], $this->exportedNames());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function aSubRequestCleansOnlyItsOwnOperations(): void
    {
        $telemetry = TelemetryFactory::tracing($this->spans, $this->reporter);
        $outer = null;
        $inner = null;
        $this->handle($this->request(
            /** @throws \Throwable */
            function () use ($telemetry, &$outer, &$inner): Response {
                $outer = $telemetry->operation('outer')->baggage(['tenant' => 'outer'])->start();
                $this->subRequest(static function () use ($telemetry, &$inner): Response {
                    $inner = $telemetry->operation('inner')->baggage(['tenant' => 'inner'])->start();

                    return new Response();
                });
                self::assertInstanceOf(RunningOperation::class, $inner);
                self::assertFalse($inner->span()->isRecording());
                self::assertTrue($outer->span()->isRecording());
                self::assertSame($outer->span()->spanId(), $this->activeTrace()?->spanId);
                self::assertSame('outer', Baggage::getCurrent()->getValue('tenant'));

                return new Response();
            },
        ));

        self::assertInstanceOf(RunningOperation::class, $outer);
        self::assertFalse($outer->span()->isRecording());
        self::assertSame(['inner', 'GET', 'outer', 'GET'], $this->exportedNames());
        self::assertNull($this->contextStorage->scope());
        $this->assertNoReports();
    }
}
