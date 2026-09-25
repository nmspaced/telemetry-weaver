<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/** The `diagnostics.*` settings reach the reporters that use them. */
#[CoversNothing]
final class DiagnosticsConfigurationTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function disabledDiagnosticsSilenceBothReporters(): void
    {
        $container = $this->compile(['diagnostics' => ['enabled' => false]]);

        $export = $container->get(ExportFailureReporter::class);
        $instrumentation = $container->get(InstrumentationFailureReporter::class);
        $logger = $container->get('logger');
        self::assertInstanceOf(ExportFailureReporter::class, $export);
        self::assertInstanceOf(InstrumentationFailureReporter::class, $instrumentation);
        self::assertInstanceOf(RecordingLogger::class, $logger);

        $export->record('Failed to export spans', new \RuntimeException('down'));
        $instrumentation->report('detach failed', 'operation');

        self::assertSame(0, $logger->count());
        self::assertSame(1, $export->total(), 'the line is silenced, the failure is still counted');
        self::assertSame(1, $instrumentation->total());
    }

    /** @throws \Throwable */
    #[Test]
    public function theBurstReachesBothReporters(): void
    {
        $container = $this->compile(['diagnostics' => ['detailed_per_process' => 1, 'min_interval_seconds' => 3600.0]]);

        $export = $container->get(ExportFailureReporter::class);
        $instrumentation = $container->get(InstrumentationFailureReporter::class);
        $logger = $container->get('logger');
        self::assertInstanceOf(ExportFailureReporter::class, $export);
        self::assertInstanceOf(InstrumentationFailureReporter::class, $instrumentation);
        self::assertInstanceOf(RecordingLogger::class, $logger);

        for ($i = 0; $i < 3; ++$i) {
            $export->record('Failed to export spans', new \RuntimeException('down'));
            $instrumentation->report('detach failed', 'operation');
        }

        self::assertSame(2, $logger->count(), 'one line per reporter, then the interval holds');
    }
}
