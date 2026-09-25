<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ContextOnlyOpener;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelIncomingTrace;
use Nmspaced\TelemetryWeaver\OpenTelemetry\ScopedTelemetryFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalMeterProvider;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalTracerProvider;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ScopedTelemetryFactory::class)]
#[CoversClass(SignalTracerProvider::class)]
#[CoversClass(SignalMeterProvider::class)]
#[CoversClass(ContextOnlyOpener::class)]
final class ScopedTelemetryFactoryTest extends TelemetryTestCase
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

    private const string SPAN_ID = 'b7ad6b7169203331';

    /** @throws \Throwable */
    #[Test]
    public function aSwitchedOffSignalHandsOutTheNoopProvider(): void
    {
        self::assertSame($this->provider, SignalTracerProvider::create($this->provider, true));
        self::assertInstanceOf(NoopTracerProvider::class, SignalTracerProvider::create($this->provider, false));
        self::assertInstanceOf(NoopMeterProvider::class, SignalMeterProvider::create(new NoopMeterProvider(), false));
    }

    /** @throws \Throwable */
    #[Test]
    public function aScopeOnTheNoopTracerProviderActivatesNoContext(): void
    {
        $telemetry = new ScopedTelemetryFactory(
            new NoopTracerProvider(),
            new NoopMeterProvider(),
            $this->contextStorage,
            new OtelDurationRecorder(),
            $this->reporter,
        )->scope('probe');

        $before = $this->contextStorage->current();
        $operation = $telemetry->operation('work')->start();

        self::assertSame($before, $this->contextStorage->current());
        self::assertNull($operation->span()->spanId());
        $operation->finish();
    }

    /** @throws \Throwable */
    #[Test]
    public function aScopeOnTheNoopTracerProviderStillCarriesBaggage(): void
    {
        $telemetry = $this->noopScope();

        $carrier = [];
        $entries = $telemetry
            ->operation('baggage')
            ->baggage(['tenant' => 'one'])
            ->run(
                /** @return array<non-empty-string, string> */
                function (OperationContext $context) use (&$carrier): array {
                    $carrier = $this->injected();

                    return $context->baggage();
                },
            );

        self::assertSame(['tenant' => 'one'], $entries);
        self::assertSame(['baggage' => 'tenant=one'], $carrier);
        $next = $telemetry
            ->operation('next')
            ->run(
                /** @return array<non-empty-string, string> */
                static fn(OperationContext $context): array => $context->baggage(),
            );
        self::assertSame([], $next, 'nothing leaks into the next operation');
    }

    /** @throws \Throwable */
    #[Test]
    public function aBoundaryOnTheNoopTracerProviderPassesTheIncomingTraceThrough(): void
    {
        $incoming = OtelIncomingTrace::extracted(
            Span::wrap(SpanContext::createFromRemoteParent(self::TRACE_ID, self::SPAN_ID, TraceFlags::SAMPLED))
                ->storeInContext(Context::getRoot()),
        );

        $telemetry = $this->noopScope();
        self::assertInstanceOf(BoundaryTelemetry::class, $telemetry);

        $carrier = $telemetry
            ->boundary('request')
            ->from($incoming)
            ->run($this->injected(...));

        self::assertSame(['traceparent' => '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01'], $carrier);
        self::assertNull($this->contextStorage->scope(), 'and the boundary released its context');
    }

    /** @throws \Throwable */
    #[Test]
    public function aScopeOnARealTracerProviderRecordsSpans(): void
    {
        $telemetry = new ScopedTelemetryFactory(
            $this->provider,
            new NoopMeterProvider(),
            $this->contextStorage,
            new OtelDurationRecorder(),
            $this->reporter,
        )->scope('probe');

        $telemetry->operation('work')->start()->finish();

        self::assertSame(['work'], $this->exportedNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function anEmptyScopeNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->noopScope('');
    }

    /** @throws \Throwable */
    #[Test]
    public function providersThatCannotResolveAScopeLeaveAWorkingNoopScope(): void
    {
        $tracers = $this->createStub(TracerProviderInterface::class);
        $tracers->method('getTracer')->willThrowException(new \RuntimeException('tracer unavailable'));
        $meters = $this->createStub(MeterProviderInterface::class);
        $meters->method('getMeter')->willThrowException(new \RuntimeException('meter unavailable'));
        $telemetry = new ScopedTelemetryFactory(
            $tracers,
            $meters,
            $this->contextStorage,
            new OtelDurationRecorder(),
            $this->reporter,
        )->scope('probe');

        self::assertSame(42, $telemetry->trace('work', static fn(): int => 42));
        $telemetry->metrics()->counter('probe.count')->add(1);

        self::assertSame([], $this->exportedNames());
        self::assertSame(
            [
                'OpenTelemetry lifecycle: Tracer resolution failed at "probe": tracer unavailable (1 total in this process)',
                'OpenTelemetry lifecycle: Meter resolution failed at "probe": meter unavailable (2 total in this process)',
            ],
            $this->logger->messages(),
        );
    }

    private function noopScope(string $name = 'probe'): Telemetry
    {
        return new ScopedTelemetryFactory(
            new NoopTracerProvider(),
            new NoopMeterProvider(),
            $this->contextStorage,
            new OtelDurationRecorder(),
            $this->reporter,
        )->scope($name);
    }

    /** @return array<array-key, mixed> */
    private function injected(): array
    {
        /** @var mixed $carrier */
        $carrier = [];
        new MultiTextMapPropagator([TraceContextPropagator::getInstance(), BaggagePropagator::getInstance()])
            ->inject($carrier, null, $this->contextStorage->current());

        return \is_array($carrier) ? $carrier : [];
    }
}
