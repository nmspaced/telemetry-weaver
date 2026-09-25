<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;

/**
 * @internal
 *
 * The single owner of flushing and shutdown for the providers the bundle created. Boundaries
 * flush each signal on its schedule; `atShutdown()` shuts providers down once and seals the pipeline.
 */
final readonly class TelemetryFlusher implements BoundaryFlush
{
    public function __construct(
        private ProviderRegistry $providers,
        private FlushBudget $budget,
        private ExportFailureReporter $failures,
        SymfonyRuntimeProfile $runtime,
    ) {
        if (!$runtime->finishesAtProcessExit()) {
            return;
        }

        $providers->finishOnExit($this);
    }

    #[\Override]
    public function atBoundary(): void
    {
        $this->flush(false);
    }

    #[\Override]
    public function atShutdown(): void
    {
        $this->flush(true);
    }

    private function flush(bool $shutdown): void
    {
        if ($this->providers->isClosed() || $this->budget->active()) {
            return;
        }

        try {
            $this->budget->begin(final: $shutdown);
            foreach ($this->providers->ordered() as $signal) {
                if ($this->budget->exhausted()) {
                    break;
                }

                $shutdown ? $signal->atShutdown() : $signal->atBoundary();
            }

            if ($this->budget->exhausted()) {
                $this->failures->record(
                    'Telemetry flush budget exhausted',
                    new \RuntimeException('Export deadline reached'),
                );
            }
        } catch (\Throwable $throwable) {
            $this->failures->record('Telemetry boundary flush failed', $throwable);
        } finally {
            if ($shutdown) {
                $this->providers->discard();
            }

            $this->budget->end();
        }
    }
}
