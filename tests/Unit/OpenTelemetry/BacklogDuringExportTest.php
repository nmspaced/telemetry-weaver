<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ExportBacklog;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\LoggerProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TracerProviderFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanSuppression\NoopSuppressionStrategy\NoopSuppressionStrategy;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BacklogDuringExportTest extends TestCase
{
    private int $exportCalls = 0;

    /** Where the SDK reports export failures; the SDK caches its writer, hence the resets. */
    private RecordingLogger $sdkLog;

    #[\Override]
    protected function setUp(): void
    {
        $this->sdkLog = new RecordingLogger();
        LoggerHolder::set($this->sdkLog);
        Logging::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        LoggerHolder::unset();
        Logging::reset();
    }

    /** @return iterable<string, array{bool, string}> */
    public static function exports(): iterable
    {
        foreach (['spans' => true, 'logs' => false] as $signal => $spans) {
            foreach (['success', 'false', 'throw', 'await-error', 'nested-flush'] as $outcome) {
                yield $signal . '/' . $outcome => [$spans, $outcome];
            }
        }
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('exports')]
    public function recordsAddedDuringExportRemainCounted(bool $spans, string $outcome): void
    {
        $backlog = new ExportBacklog(batchSize: 2, capacity: 4);
        $emit = static function (): void {};
        $provider = null;
        $export = /** @throws \Throwable */ function () use (&$emit, &$provider, $outcome): FutureInterface {
            if (++$this->exportCalls !== 1) {
                return new CompletedFuture(true);
            }

            if ($outcome === 'throw') {
                $emit();
                $emit();
                throw new \RuntimeException('Synchronous export failure');
            }

            $future = $this->createStub(FutureInterface::class);
            $await = /** @throws \RuntimeException */ static function () use (&$emit, &$provider, $outcome): bool {
                $emit();
                $emit();
                if ($outcome === 'nested-flush') {
                    $provider?->forceFlush();
                }

                if ($outcome === 'await-error') {
                    throw new \RuntimeException('Asynchronous export failure');
                }

                return $outcome !== 'false';
            };
            $future->method('await')->willReturnCallback($await);

            return $future;
        };
        [$emit, $provider] = $this->pipeline($spans, $backlog, $export);
        $emit();
        $emit();
        $provider->forceFlush();

        self::assertSame(
            \in_array($outcome, ['throw', 'await-error'], true),
            $this->sdkLog->messages() !== [],
            'a failed export still reaches the SDK error path, and only a failed one',
        );

        // A nested flush also requests delivery of the newly emitted batch.
        self::assertSame($outcome !== 'nested-flush', $backlog->holdsFullBatch());
        for ($i = 0; $i < 4; ++$i) {
            $emit();
        }

        self::assertSame($outcome === 'nested-flush' ? 0 : 2, $backlog->takeDropped());
        $provider->forceFlush();
        self::assertFalse($backlog->holdsFullBatch());
        $provider->shutdown();
    }

    /** @return iterable<string, array{bool}> */
    public static function signals(): iterable
    {
        yield 'spans' => [true];
        yield 'logs' => [false];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('signals')]
    public function completedBatchesFreeCapacityBeforeTheNextExport(bool $spans): void
    {
        $backlog = new ExportBacklog(batchSize: 2, capacity: 4);
        $emit = static function (): void {};
        $export = function () use (&$emit): FutureInterface {
            if (++$this->exportCalls === 2) {
                $emit();
                $emit();
                $emit();
            }

            return new CompletedFuture(true);
        };
        [$emit, $provider] = $this->pipeline($spans, $backlog, $export);
        for ($i = 0; $i < 4; ++$i) {
            $emit();
        }

        $provider->forceFlush();
        self::assertTrue($backlog->holdsFullBatch());
        self::assertSame(1, $backlog->takeDropped());
        $provider->forceFlush();
        self::assertFalse($backlog->holdsFullBatch());
        $provider->shutdown();
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('signals')]
    public function shutdownDoesNotCountRecordsTheSdkNoLongerAccepts(bool $spans): void
    {
        $backlog = new ExportBacklog(batchSize: 2, capacity: 2);
        $emit = static function (): void {};
        $export = static function () use (&$emit): FutureInterface {
            $emit();
            $emit();

            return new CompletedFuture(true);
        };
        [$emit, $provider] = $this->pipeline($spans, $backlog, $export);
        $emit();
        $emit();
        $provider->shutdown();
        $emit();
        $emit();
        self::assertFalse($backlog->holdsFullBatch());
        self::assertSame(0, $backlog->takeDropped());
    }

    /**
     * @param \Closure(): FutureInterface<bool> $export
     * @return array{\Closure(): void, TracerProviderInterface|LoggerProviderInterface}
     * @throws \Throwable
     * @experimental uses the SDK's experimental NoopSuppressionStrategy
     */
    private function pipeline(bool $spans, ExportBacklog $backlog, \Closure $export): array
    {
        if ($spans) {
            $exporter = $this->createStub(SpanExporterInterface::class);
            $exporter->method('export')->willReturnCallback($export);
            $exporter->method('forceFlush')->willReturn(true);
            $exporter->method('shutdown')->willReturn(true);
            $provider = new TracerProviderFactory(
                ResourceInfoFactory::emptyResource(),
                new NoopMeterProvider(),
                $exporter,
                new NoopSuppressionStrategy(),
            )->create($backlog);
            $tracer = $provider->getTracer('backlog-test');

            return [
                static function () use ($tracer): void {
                    $tracer->spanBuilder('record')->startSpan()->end();
                },
                $provider,
            ];
        }

        $exporter = $this->createStub(LogRecordExporterInterface::class);
        $exporter->method('export')->willReturnCallback($export);
        $exporter->method('forceFlush')->willReturn(true);
        $exporter->method('shutdown')->willReturn(true);
        $provider = new LoggerProviderFactory(ResourceInfoFactory::emptyResource(), $exporter)->create($backlog);
        $logger = $provider->getLogger('backlog-test');

        return [
            static function () use ($logger): void {
                $logger->emit(new LogRecord('record'));
            },
            $provider,
        ];
    }
}
