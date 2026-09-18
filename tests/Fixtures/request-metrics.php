<?php

declare(strict_types=1);

// Run against delta-collector.yaml with OTEL_EXPORTER_OTLP_METRICS_ENDPOINT set.
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MeterProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\RequestMetricPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$_SERVER['OTEL_METRICS_EXPORTER'] = 'otlp';
$_SERVER['OTEL_EXPORTER_OTLP_PROTOCOL'] = 'http/json';
// Two sequential request pipelines per writer, then a new child (different PID).
foreach ([['host-a', 100, 3], ['host-a', 100, 5], ['host-b', 100, 7], ['host-b', 100, 11], ['host-a', 101, 13]] as [
    $host,
    $pid,
    $value,
]) {
    $failures = new ExportFailureReporter(new NullLogger());
    $budget = new FlushBudget();
    $gate = ExportGate::forBudget($budget);
    $registry = new ProviderRegistry($gate, $failures);
    $resource = ResourceInfo::create(Attributes::create([
        'service.name' => 'request-metrics-probe',
        'host.name' => $host,
        'process.pid' => $pid,
    ]));
    $policy = RequestMetricPolicy::forRuntime(
        SymfonyRuntimeProfile::fromKernel(0, true),
        'delta',
        $failures,
        $resource,
    );
    $exporter = new MetricExporterFactory(
        new ResilientExporters($failures, $gate),
        new BudgetedOtlpTransports($gate),
        $policy,
    )
        ->create();
    $provider = $registry->metrics(new MeterProviderFactory($resource, $exporter)->create());
    $provider->getMeter('probe')->createCounter('requests')->add($value);
    $flusher = new TelemetryFlusher($registry, $budget, $failures, SymfonyRuntimeProfile::fromKernel(0, true));
    $flusher->atShutdown();
    $flusher->atShutdown();
    assert($failures->total() === 0, 'Collector export must succeed');
    usleep(10_000);
}
