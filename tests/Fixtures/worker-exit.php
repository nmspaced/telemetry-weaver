<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fixtures;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientTracesExporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ProviderRegistry;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\Internal\Runtime\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

// Usage: worker-exit.php <worker|request|fatal|released>
$mode = $argv[1] ?? 'worker';
$runtime = $mode === 'request'
    ? SymfonyRuntimeProfile::fromKernel(0, true)
    : SymfonyRuntimeProfile::fromKernel(1, true);

$budget = new FlushBudget(1000);
$gate = ExportGate::forBudget($budget);
$failures = new ExportFailureReporter(new RecordingLogger());
$registry = new ProviderRegistry($gate, $failures);
$exported = new InMemoryExporter();
$provider = TracerProvider::builder()
    ->addSpanProcessor(
        new BatchSpanProcessor(
            new ResilientTracesExporter($exported, $failures, $gate),
            Clock::getDefault(),
            autoFlush: false,
        ),
    )
    ->build();
$registry->traces($provider);
$flusher = new TelemetryFlusher($registry, $budget, $failures, $runtime);

// Registered after the gate's own callback, so it observes what the process exit did.
\register_shutdown_function(static function () use ($exported, $gate, $failures): void {
    echo
        \json_encode([
            'exported' => \count($exported->getSpans()),
            'closed' => $gate->isClosed(),
            'failures' => $failures->total(),
        ])
    ;
});

// The span sits in the batch queue: no boundary has drained it when the worker loop returns.
$provider->getTracer('worker-exit')->spanBuilder('last request')->startSpan()->end();

if ($mode === 'released') {
    // A container that was replaced must not be flushed by a callback that outlived it.
    unset($flusher);
}

if ($mode === 'fatal') {
    throw new \LogicException('the worker died');
}
