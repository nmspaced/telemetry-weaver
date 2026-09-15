<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Lifecycle\TelemetryFlushSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientTracesExporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ProviderRegistry;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\Internal\Runtime\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\ResourceInfoFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SpanExporterFactory;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$runtime = SymfonyRuntimeProfile::fromKernel(0, true);
$budget = new FlushBudget(100);
$gate = ExportGate::forBudget($budget);
$failures = new ExportFailureReporter(new NullLogger());
$registry = new ProviderRegistry($gate, $failures);
$resource = new ResourceInfoFactory($runtime)->create();
$endpoint = $_GET['endpoint'] ?? '';
$directory = $_GET['directory'] ?? '';
assert(is_string($directory), 'The harness supplies a directory');
$mode = $_GET['mode'] ?? 'normal';
$exporter = new ResilientTracesExporter(new InMemoryExporter(), $failures, $gate);
if ($endpoint !== '') {
    $_SERVER['OTEL_TRACES_EXPORTER'] = 'otlp';
    $_SERVER['OTEL_EXPORTER_OTLP_PROTOCOL'] = 'http/json';
    $_SERVER['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'] = $endpoint;
    $exporter = new SpanExporterFactory(
        new ResilientExporters($failures, $gate),
        new BudgetedOtlpTransports($gate),
    )->create();
}

assert($exporter !== null, 'The fixture must have a real exporter');
$provider = TracerProvider::builder()
    ->setResource($resource)
    ->addSpanProcessor(new BatchSpanProcessor($exporter, Clock::getDefault(), autoFlush: false))
    ->build();
$registry->traces($provider);
$provider->getTracer('fpm-test')->spanBuilder('request')->startSpan()->end();
register_shutdown_function(static function () use ($gate, $provider, $directory): void {
    // A late SDK/user callback must not reopen network export after the gate closes.
    $provider->shutdown();
    file_put_contents($directory . '/shutdown', $gate->isClosed() ? 'closed' : 'open');
});
header('Content-Type: application/json');
echo json_encode(['pid' => getmypid(), 'resource' => $resource->getAttributes()->toArray()]);
fastcgi_finish_request();
if ($mode === 'exit') {
    exit();
}

new TelemetryFlushSubscriber(new TelemetryFlusher($registry, $budget, $failures, $runtime), $runtime)->onTerminate();
file_put_contents($directory . '/terminated', 'done');
