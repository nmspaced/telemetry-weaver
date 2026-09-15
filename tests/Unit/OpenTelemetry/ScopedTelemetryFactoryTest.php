<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\OpenTelemetry\ScopedTelemetryFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalMeterProvider;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalTracerProvider;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ScopedTelemetryFactory::class)]
#[CoversClass(SignalTracerProvider::class)]
#[CoversClass(SignalMeterProvider::class)]
final class ScopedTelemetryFactoryTest extends TelemetryTestCase
{
    #[Test]
    public function aSwitchedOffSignalHandsOutTheNoopProvider(): void
    {
        self::assertSame($this->provider, SignalTracerProvider::create($this->provider, true));
        self::assertInstanceOf(NoopTracerProvider::class, SignalTracerProvider::create($this->provider, false));
        self::assertInstanceOf(NoopMeterProvider::class, SignalMeterProvider::create(new NoopMeterProvider(), false));
    }

    /**
     * A span opener over a no-op tracer would still activate a context scope for every span;
     * the no-op opener activates nothing.
     */
    #[Test]
    public function aScopeOnTheNoopTracerProviderActivatesNoContext(): void
    {
        $telemetry = new ScopedTelemetryFactory(
            new NoopTracerProvider(),
            new NoopMeterProvider(),
            $this->contextStorage,
            $this->reporter,
        )->scope('probe');

        $before = $this->contextStorage->current();
        $operation = $telemetry->operation('work')->start();

        self::assertSame($before, $this->contextStorage->current());
        self::assertFalse($operation->span()->context()->isValid());
        $operation->finish();
    }

    #[Test]
    public function aScopeOnARealTracerProviderRecordsSpans(): void
    {
        $telemetry = new ScopedTelemetryFactory(
            $this->provider,
            new NoopMeterProvider(),
            $this->contextStorage,
            $this->reporter,
        )->scope('probe');

        $telemetry->operation('work')->start()->finish();

        self::assertSame(['work'], $this->exportedNames());
    }
}
