<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\LogExportPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\OtelLogHandler;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\TraceContextProcessor;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelLogCorrelation;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * A real `LoggerProvider` exporting into memory, wired behind an `OtelLogHandler`. Shared by
 * the handler test classes so each one only carries the scenarios specific to it.
 *
 * @internal
 */
abstract class OtelLogHandlerTestCase extends TestCase
{
    protected InMemoryExporter $exporter;

    protected LoggerProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $provider = LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor($this->exporter))
            ->build();
        Assert::assertInstanceOf(LoggerProvider::class, $provider);
        $this->provider = $provider;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->provider->shutdown();
    }

    /**
     * @param non-empty-string $level
     * @param list<string> $excludedChannels
     * @param bool $correlated whether the logger's stack carries {@see TraceContextProcessor},
     *                         which is what the handler reads a record's trace from
     */
    protected function handler(
        string $level = 'debug',
        array $excludedChannels = [],
        bool $correlated = false,
    ): OtelLogHandler {
        return new OtelLogHandler(
            $this->provider,
            new InstrumentationFailureReporter(new RecordingLogger()),
            new LogExportPolicy($level, $excludedChannels),
            $correlated ? new OtelLogCorrelation() : null,
        );
    }

    /** @return list<ReadableLogRecord> */
    protected function records(): array
    {
        $records = \array_values(\iterator_to_array($this->exporter->getStorage()));
        Assert::assertContainsOnlyInstancesOf(ReadableLogRecord::class, $records);

        return $records;
    }

    protected function record(int $index = 0): ReadableLogRecord
    {
        return $this->records()[$index] ?? Assert::fail('no exported record at index ' . $index);
    }
}
