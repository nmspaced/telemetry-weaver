<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SignalFlusher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher;

final class Flushers
{
    /** A gate no boundary is using: exports pass until something closes it. */
    public static function openGate(): ExportGate
    {
        return ExportGate::forBudget(new FlushBudget());
    }

    public static function coordinating(
        SignalFlusher $traces,
        SignalFlusher $logs,
        SignalFlusher $metrics,
        FlushBudget $budget,
        ExportFailureReporter $failures,
    ): TelemetryFlusher {
        $providers = new ProviderRegistry(ExportGate::forBudget($budget), $failures);
        foreach ([$traces->signal() => $traces, $logs->signal() => $logs, $metrics->signal() => $metrics] as $signal) {
            $providers->add($signal);
        }

        // The request profile registers no process-exit flush: a coordinator built for one
        // test must not leave a final export behind for PHPUnit's own shutdown to run.
        return new TelemetryFlusher($providers, $budget, $failures, SymfonyRuntimeProfile::fromKernel(0, true));
    }
}
