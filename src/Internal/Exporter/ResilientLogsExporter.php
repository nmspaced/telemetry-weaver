<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;

final readonly class ResilientLogsExporter implements LogRecordExporterInterface
{
    public function __construct(
        private LogRecordExporterInterface $delegate,
        private ExportFailureReporter $reporter,
        private ExportGate $gate,
    ) {}

    /**
     * @return FutureInterface<mixed>
     */
    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if (!$this->gate->allowsExport()) {
            return new CompletedFuture(false);
        }

        $reporter = $this->reporter;
        try {
            return $this->delegate
                ->export($batch, $cancellation)
                ->catch(static function (\Throwable $exception) use ($reporter): bool {
                    $reporter->record('Failed to export log records', $exception);

                    return false;
                });
        } catch (\Throwable $throwable) {
            $this->reporter->record('Failed to export log records', $throwable);

            return new CompletedFuture(false);
        }
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if (!$this->gate->allowsExport()) {
            return false;
        }

        try {
            return $this->delegate->shutdown($cancellation);
        } catch (\Throwable $throwable) {
            $this->reporter->record('Failed to shut down logs exporter', $throwable);

            return false;
        }
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        if (!$this->gate->allowsExport()) {
            return false;
        }

        try {
            return $this->delegate->forceFlush($cancellation);
        } catch (\Throwable $throwable) {
            $this->reporter->record('Failed to force flush logs exporter', $throwable);

            return false;
        }
    }
}
