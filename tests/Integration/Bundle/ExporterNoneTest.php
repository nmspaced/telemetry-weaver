<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * `OTEL_TRACES_EXPORTER=none` is how the SDK is told not to export anything, and the
 * SDK's own factories answer it with null. Null has to survive the trip through the
 * container: it used to reach a decorator that only accepts a real exporter, so the
 * documented way of switching a signal off took the whole container down with it.
 */
final class ExporterNoneTest extends ContainerTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['OTEL_TRACES_EXPORTER'] = 'none';
        $_SERVER['OTEL_METRICS_EXPORTER'] = 'none';
    }

    /** @throws \Throwable */
    #[Test]
    public function aSignalWithoutAnExporterStillBuildsItsProvider(): void
    {
        $container = $this->compile();

        self::assertInstanceOf(TracerProviderInterface::class, $container->get(TracerProviderInterface::class));
        self::assertInstanceOf(MeterProviderInterface::class, $container->get(MeterProviderInterface::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function aTracerFromASignalWithoutAnExporterStillWorks(): void
    {
        $container = $this->compile();

        $tracer = $container->get(TracerInterface::class);
        self::assertInstanceOf(TracerInterface::class, $tracer);

        // Nothing is exported, but instrumentation must not notice: a span still opens
        // and closes without an exporter behind it.
        $tracer->spanBuilder('probe')->startSpan()->end();
    }
}
