<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientTracesExporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ProviderRegistry;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\Internal\Runtime\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExportGateTest extends TestCase
{
    #[Test]
    public function exhaustedFinalizationNeverReopensExportWhenTheDeadlineIsCleared(): void
    {
        $clock = new FrozenClock();
        $budget = new FlushBudget(100, $clock);
        $gate = ExportGate::forBudget($budget);
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $registry = new ProviderRegistry($gate, $reporter);
        $provider = $this->createMock(TracerProviderInterface::class);
        $provider
            ->expects(self::once())
            ->method('shutdown')
            ->willReturnCallback(static function () use ($clock): bool {
                $clock->advanceSeconds(0.1);

                return false;
            });
        $provider->expects(self::never())->method('forceFlush');
        $registry->traces($provider);
        $delegate = $this->createMock(SpanExporterInterface::class);
        $delegate->expects(self::never())->method('export');
        $delegate->expects(self::never())->method('shutdown');
        $delegate->expects(self::never())->method('forceFlush');
        $exporter = new ResilientTracesExporter($delegate, $reporter, $gate);
        $flusher = new TelemetryFlusher($registry, $budget, $reporter, SymfonyRuntimeProfile::fromKernel(0, true));
        $flusher->atShutdown();
        self::assertFalse($budget->active());
        self::assertFalse($exporter->export([])->await());
        self::assertFalse($exporter->forceFlush());
        self::assertFalse($exporter->shutdown());
        $registry->traces($provider);
        $flusher->atShutdown();
        $flusher->atBoundary();
        self::assertSame([], \iterator_to_array($registry->ordered()));
    }

    #[Test]
    public function shutdownRegistryDoesNotKeepOldPipelinesAlive(): void
    {
        $gate = ExportGate::forBudget(new FlushBudget());
        $reference = \WeakReference::create($gate);
        unset($gate);
        self::assertNull($reference->get());
    }
}
