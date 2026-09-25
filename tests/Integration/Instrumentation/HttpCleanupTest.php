<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(RequestTraceRegistry::class)]
final class HttpCleanupTest extends HttpTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function repeatingTheMainRequestEventKeepsItsOriginalOperation(): void
    {
        $request = $this->request(static fn(): Response => new Response());
        $this->dispatcher->dispatch(
            new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST),
            KernelEvents::REQUEST,
        );
        $first = $this->scopes->of($request);
        $boundary = $this->contextStorage->scope();

        $response = $this->kernel->handle($request);

        self::assertSame($first, $this->scopes->of($request));
        self::assertSame([], $this->exported());
        self::assertNotNull($boundary);
        $this->assertNoReports();

        $this->kernel->terminate($request, $response);

        self::assertCount(1, $this->exported());
        self::assertNull($this->contextStorage->scope());
    }

    /** @throws \Throwable */
    #[Test]
    public function theMainSpanOutlivesFinishRequestButNotTerminate(): void
    {
        $baseline = $this->contextStorage->scope();
        $request = $this->request(static fn(): Response => new Response());
        $response = $this->kernel->handle($request);

        self::assertSame([], $this->exported(), 'the span is still open after the kernel returned');
        self::assertSame($baseline, $this->contextStorage->scope(), 'its context is already released');
        self::assertNotNull($this->scopes->of($request));

        $this->kernel->terminate($request, $response);

        self::assertCount(1, $this->exported());
        self::assertNull($this->scopes->of($request));
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function aStreamedBodyIsSentWhileTheSpanIsOpenButNotActive(): void
    {
        $activeInsideCallback = 'never ran';
        $request = $this->request(static function () use (&$activeInsideCallback): Response {
            return new StreamedResponse(static function () use (&$activeInsideCallback): void {
                $activeInsideCallback = Context::storage()->scope();
            });
        });

        $response = $this->kernel->handle($request);
        $response->sendContent();

        self::assertNull($activeInsideCallback, 'the server span is deliberately not active here');
        self::assertSame([], $this->exported(), 'but it is still open, so the body counts toward its duration');

        $this->kernel->terminate($request, $response);
        self::assertCount(1, $this->exported());
    }

    /** @throws \Throwable */
    #[Test]
    public function aListenerThatBreaksTerminateDoesNotStrandTheSpan(): void
    {
        /** @var \RuntimeException|null $pending */
        $pending = new \RuntimeException('terminate listener failed');
        $this->dispatcher->addListener(
            KernelEvents::TERMINATE,
            /** @throws \RuntimeException on the first terminate only */
            static function () use (&$pending): void {
                $failure = $pending;
                $pending = null;

                if ($failure !== null) {
                    throw $failure;
                }
            },
            1024,
        );

        $request = $this->request(static fn(): Response => new Response('', 201));
        $response = $this->kernel->handle($request);

        try {
            $this->kernel->terminate($request, $response);
            self::fail('the listener exception must reach the caller');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('terminate listener failed', $runtimeException->getMessage());
        }

        self::assertSame([], $this->exported(), 'nothing closed it yet');
        $this->scopes->reset();

        $this->handle($this->request(static fn(): Response => new Response()));

        $stranded = $this->exportedSpan();
        self::assertSame(201, $stranded->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_UNSET, $stranded->getStatus()->getCode());
        self::assertNull(
            $stranded->getAttributes()->get('telemetry.incomplete'),
            'a known response survives a missing terminate',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function resetDoesNotInventAnOutcomeOrRecoveryAttributes(): void
    {
        $this->dispatcher->addListener(
            KernelEvents::REQUEST,
            /** @throws \RuntimeException always */
            static fn(): never => throw new \RuntimeException('the request never got a response'),
            1024,
        );

        $request = $this->request(static fn(): Response => new Response());

        try {
            $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, catch: false);
            self::fail('the listener exception must reach the caller');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('the request never got a response', $runtimeException->getMessage());
        }

        $this->scopes->reset();

        $span = $this->exportedSpan();
        self::assertNull($span->getAttributes()->get('telemetry.incomplete'));
        self::assertNull($span->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    /** @throws \Throwable */
    #[Test]
    public function anExcludedRequestDoesNotResetThePreviousOperation(): void
    {
        $this->boot(excludedPaths: ['/health']);
        $request = $this->request(static fn(): Response => new Response('', 204));
        $this->kernel->handle($request);

        self::assertSame([], $this->exported());

        $this->handle($this->request(static fn(): Response => new Response(), uri: '/health/live'));

        self::assertSame([], $this->exported());
        self::assertNotNull($this->scopes->of($request));
        $this->scopes->reset();
        self::assertNull($this->scopes->of($request));
        self::assertCount(1, $this->exported());
        self::assertSame(204, $this->exportedSpan()->getAttributes()->get('http.response.status_code'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnfinishedRequestIsNotKeptAliveByTheRegistry(): void
    {
        $request = $this->request(static fn(): Response => new Response('', 202));
        $this->kernel->handle($request);
        $reference = \WeakReference::create($request);
        $trace = $this->scopes->of($request);
        self::assertNotNull($trace);
        $traceReference = \WeakReference::create($trace);
        unset($trace);
        unset($request);
        \gc_collect_cycles();

        self::assertNull($reference->get(), 'the registry must not be what keeps a request alive');
        self::assertNull($traceReference->get(), 'the entry goes with its key');
        self::assertSame([], $this->exported(), 'a request nobody saw finish records nothing');
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnfinishedRequestStillHeldIsReleasedByReset(): void
    {
        $request = $this->request(static fn(): Response => new Response('', 202));
        $this->kernel->handle($request);

        self::assertNotNull($this->scopes->of($request));
        self::assertSame([], $this->exported());

        $this->scopes->reset();

        self::assertNull($this->scopes->of($request));
        self::assertCount(1, $this->exported());
        self::assertSame(202, $this->exportedSpan()->getAttributes()->get('http.response.status_code'));
    }

    #[Test]
    public function reopeningARequestKeepsTheFirstOperation(): void
    {
        $request = new Request();
        $method = HttpMethod::from($request);

        $first = $this->scopes->open($request, $method);
        $second = $this->scopes->open($request, $method);

        self::assertSame($first, $second);
        $this->assertNoReports();

        $this->scopes->reset();
        self::assertCount(1, $this->exported(), 'one open, one span');
    }

    /** @throws \Throwable */
    #[Test]
    public function resettingAnAlreadyFinishedRequestIsSafe(): void
    {
        $this->handle($this->request(static fn(): Response => new Response()));

        $this->scopes->reset();
        $this->scopes->reset();

        self::assertCount(1, $this->exported());
        self::assertNull(Context::storage()->scope());
        $this->assertNoReports();
    }
}
