<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;

/**
 * @internal The single owner of delivery and finalization for the providers the bundle created.
 *
 * HTTP terminate, Console terminate and the Messenger worker events all come here, and so
 * does PHP shutdown for pipelines that outlive requests (see `ExportGate`). There is no second
 * channel: the provider factories no longer register SDK shutdown callbacks, because those
 * exported once per provider, after the boundary budget had ended, and `ExportingReader`
 * collects again on `shutdown()` — a pre-flush followed by one of them sends metrics twice.
 *
 * A boundary flushes each signal on its own schedule. `atShutdown()` is final: it calls
 * `shutdown()` rather than `forceFlush()`, so the SDK does exactly one last collection, and
 * then seals the pipeline whether or not every signal fit into the budget.
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
        // Export diagnostics can themselves log. A nested boundary must neither recurse
        // into providers nor replace the deadline of the outer flush.
        if ($this->providers->isClosed() || $this->budget->active()) {
            return;
        }

        try {
            $this->budget->begin(final: $shutdown);
            // Traces first: a span batch is what an operator looks at when a request went
            // wrong, and the three signals share the same blocking export budget.
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
                // Also seals signals which did not fit in the budget. Do not invoke
                // their providers: arbitrary shutdown code could start another wait.
                $this->providers->discard();
            }

            $this->budget->end();
        }
    }
}
