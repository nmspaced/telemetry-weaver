<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\Test;

/** `OTEL_*_EXPORTER=none` builds working providers that export nothing. */
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

        $tracer->spanBuilder('probe')->startSpan()->end();
    }
}
