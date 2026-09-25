<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\LoggerProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TracerProviderFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\CountingSpanExporter;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanSuppression\NoopSuppressionStrategy\NoopSuppressionStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Ending a span or writing a log record must never reach the network. */
#[CoversClass(TracerProviderFactory::class)]
#[CoversClass(LoggerProviderFactory::class)]
final class ProducerPathExportTest extends TestCase
{
    private const int MORE_THAN_ONE_BATCH = 600;

    #[\Override]
    protected function setUp(): void
    {
        $_SERVER['OTEL_BSP_MAX_EXPORT_BATCH_SIZE'] = '10';
        $_SERVER['OTEL_BLRP_MAX_EXPORT_BATCH_SIZE'] = '10';
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset($_SERVER['OTEL_BSP_MAX_EXPORT_BATCH_SIZE'], $_SERVER['OTEL_BLRP_MAX_EXPORT_BATCH_SIZE']);
    }

    /**
     * @throws \Throwable
     *
     * @experimental uses the SDK's NoopSuppressionStrategy, itself marked experimental upstream
     */
    #[Test]
    public function endingSpansDoesNotExportUntilAFlush(): void
    {
        $exporter = new CountingSpanExporter();
        $provider = new TracerProviderFactory(
            ResourceInfoFactory::emptyResource(),
            new NoopMeterProvider(),
            $exporter,
            new NoopSuppressionStrategy(),
        )->create();
        $tracer = $provider->getTracer('test');

        for ($i = 0; $i < self::MORE_THAN_ONE_BATCH; ++$i) {
            $tracer->spanBuilder('probe')->startSpan()->end();
        }

        self::assertSame(0, $exporter->exported, 'a full batch was exported from span end');

        $provider->forceFlush();

        self::assertGreaterThan(0, $exporter->exported, 'the boundary flush still delivers');

        $provider->shutdown();
    }

    #[Test]
    public function emittingLogRecordsDoesNotExportUntilAFlush(): void
    {
        $exporter = new InMemoryLogExporter();
        $provider = new LoggerProviderFactory(ResourceInfoFactory::emptyResource(), $exporter)->create();
        $logger = $provider->getLogger('test');

        for ($i = 0; $i < self::MORE_THAN_ONE_BATCH; ++$i) {
            $logger->emit(new LogRecord('probe'));
        }

        self::assertCount(0, $exporter->getStorage(), 'a full batch was exported from log emit');

        $provider->forceFlush();

        self::assertNotCount(0, $exporter->getStorage(), 'the boundary flush still delivers');

        $provider->shutdown();
    }

    #[Test]
    public function aFullLogQueueDropsInsteadOfExporting(): void
    {
        $_SERVER['OTEL_BLRP_MAX_QUEUE_SIZE'] = '20';

        try {
            $exporter = new InMemoryLogExporter();
            $provider = new LoggerProviderFactory(ResourceInfoFactory::emptyResource(), $exporter)->create();
            $logger = $provider->getLogger('test');

            for ($i = 0; $i < self::MORE_THAN_ONE_BATCH; ++$i) {
                $logger->emit(new LogRecord('probe'));
            }

            self::assertCount(0, $exporter->getStorage());

            $provider->forceFlush();

            self::assertCount(20, $exporter->getStorage(), 'only what fit in the queue is delivered');

            $provider->shutdown();
        } finally {
            unset($_SERVER['OTEL_BLRP_MAX_QUEUE_SIZE']);
        }
    }
}
