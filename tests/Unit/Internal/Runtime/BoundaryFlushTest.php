<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\FlushPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SignalFlusher;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Fake\ThrowingMeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignalFlusher::class)]
final class BoundaryFlushTest extends TestCase
{
    private InMemoryExporter $exporter;

    private MeterProviderInterface $provider;

    private FrozenClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        FlushPolicy::resetProcessState();
        $this->exporter = new InMemoryExporter();
        $this->provider = MeterProvider::builder()->addReader(new ExportingReader($this->exporter))->build();
        $this->clock = new FrozenClock();
    }

    #[\Override]
    protected function tearDown(): void
    {
        FlushPolicy::resetProcessState();
        $this->provider->shutdown();
    }

    private function flush(): SignalFlusher
    {
        return new SignalFlusher(
            $this->provider,
            FlushPolicy::every('metrics', 60_000, $this->clock),
            new ExportFailureReporter(new RecordingLogger()),
        );
    }

    #[Test]
    public function theFirstBoundaryExports(): void
    {
        $this->provider->getMeter('test')->createCounter('probe')->add(1);

        $this->flush()->atBoundary();

        self::assertNotSame([], $this->exporter->collect(true));
    }

    /**
     * The interval is what keeps a busy worker from paying a blocking export at the end of
     * every request; the first boundary starts it rather than being exempt from it.
     */
    #[Test]
    public function aBoundaryInsideTheIntervalExportsNothing(): void
    {
        $this->provider->getMeter('test')->createCounter('probe')->add(1);

        $flush = $this->flush();
        $flush->atBoundary();

        $this->exporter->collect(true);

        $this->provider->getMeter('test')->createCounter('probe')->add(1);
        $flush->atBoundary();

        self::assertSame([], $this->exporter->collect(true));
    }

    /**
     * A failing export must not break request termination: the response is already sent, but an exception here still aborts a worker loop.
     */
    #[Test]
    public function anExportFailureIsSwallowedAndReported(): void
    {
        $logger = new RecordingLogger();
        $flush = new SignalFlusher(
            new ThrowingMeterProvider(),
            FlushPolicy::every('metrics', 60_000, $this->clock),
            new ExportFailureReporter($logger),
        );

        $flush->atBoundary();
        $flush->atBoundary();

        self::assertSame(1, $logger->count());
    }
}
