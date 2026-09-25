<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpMetricsTestCase;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class HttpMetricsFailureTest extends HttpMetricsTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function durationReceivesTheServerContextAfterItHasBeenDetached(): void
    {
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram
            ->expects(self::once())
            ->method('record')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(
                    fn(ContextInterface $context): bool => (
                        Span::fromContext($context)->getContext()->getSpanId() === $this
                            ->exportedSpan()
                            ->getContext()
                            ->getSpanId()
                    ),
                ),
            );
        $this->subscribe($this->meterWithDurationHistogram($histogram));
        $this->dispatcher->removeSubscriber($this->subscriber);
        $this->dispatcher->addListener(KernelEvents::REQUEST, $this->subscriber->onRequest(...), 2047);
        $this->dispatcher->addListener(KernelEvents::TERMINATE, $this->subscriber->onTerminate(...), -2049);
        $this->handle($this->request(static fn(): Response => new Response()));
    }

    /** @throws \Throwable */
    #[Test]
    public function aBrokenHistogramDoesNotPreventCleanupOrResponseDelivery(): void
    {
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram
            ->expects(self::once())
            ->method('record')
            ->willThrowException(new \RuntimeException('metric failure'));
        $this->subscribe($this->meterWithDurationHistogram($histogram));
        $request = $this->request(static fn(): Response => new Response('delivered'));
        $response = $this->handle($request);
        $this->subscriber->onTerminate(new TerminateEvent($this->kernel, $request, $response));
        $this->measurements->reset();
        self::assertSame('delivered', $response->getContent());
        self::assertNull($this->measurements->of($request));
        self::assertSame(1, $this->reporter->total());
    }

    /** @throws \Throwable */
    #[Test]
    public function recordsMetricsWithoutAnyServerSpan(): void
    {
        foreach ($this->dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            if (!\is_array($listener) || !$listener[0] instanceof HttpServerTracingSubscriber) {
                continue;
            }

            $this->dispatcher->removeSubscriber($listener[0]);
        }

        $this->handle($this->request(static fn(): Response => new Response()));
        self::assertSame([], $this->exported());
        $metric = $this->metric('http.server.request.duration');
        self::assertInstanceOf(Histogram::class, $metric->data);
        self::assertCount(1, $metric->data->dataPoints);
    }

    /**
     * The duration histogram by name.
     *
     * @throws \Throwable
     */
    private function meterWithDurationHistogram(HistogramInterface $duration): MeterInterface
    {
        $meter = $this->createStub(MeterInterface::class);
        $meter
            ->method('createHistogram')
            ->willReturnCallback(
                /**
                 * @throws \PHPUnit\Framework\MockObject\Exception
                 * @throws \PHPUnit\Event\NoPreviousThrowableException
                 */
                fn(string $name): HistogramInterface => $name === 'http.server.request.duration'
                    ? $duration
                    : $this->createStub(HistogramInterface::class),
            );

        return $meter;
    }
}
