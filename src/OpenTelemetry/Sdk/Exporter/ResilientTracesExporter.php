<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

final readonly class ResilientTracesExporter implements SpanExporterInterface
{
    public function __construct(
        private SpanExporterInterface $delegate,
        private ExportFailureReporter $reporter,
        private ExportGate $gate,
    ) {}

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
                    $reporter->record('Failed to export spans', $exception);

                    return false;
                });
        } catch (\Throwable $throwable) {
            $this->reporter->record('Failed to export spans', $throwable);

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
            $this->reporter->record('Failed to shut down spans exporter', $throwable);

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
            $this->reporter->record('Failed to force flush spans exporter', $throwable);

            return false;
        }
    }
}
