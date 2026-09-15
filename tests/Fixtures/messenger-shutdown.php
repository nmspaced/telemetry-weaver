<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fixtures;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\Messenger\Envelope;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

// Escalate even vendor notices: PHPUnit's source restriction must not mask DebugScope.
\set_error_handler(
    /** @throws \ErrorException */ static function (int $severity, string $message): never {
        throw new \ErrorException($message, 0, $severity);
    },
);

$logger = new RecordingLogger();
$reporter = new InstrumentationFailureReporter($logger);
$exporter = new InMemoryExporter();
$provider = new TracerProvider(new SimpleSpanProcessor($exporter));
$telemetry = TelemetryFactory::tracing(
    new SpanOpener($provider->getTracer('test'), Context::storage(), $reporter),
    $reporter,
);
// Global owners survive the call-stack unwind and exercise the shutdown callback.
$retained = $telemetry->operation('retained')->start();
$retainedChild = $telemetry->operation('retained child')->start();
$consumption = new MessengerConsumption(
    new MessengerTelemetry($telemetry),
    TraceContextPropagator::getInstance(),
    $reporter,
);
$consumption->run(
    new Envelope(new \stdClass()),
    'async',
    /** @throws \Throwable */ static function () use ($telemetry, $exporter, $logger): never {
        $telemetry->operation('nested')->run(static function () use ($exporter, $logger): never {
            // Registered after the first activation: verify the production cleanup callback,
            // not a direct call to a test-only reset method.
            \register_shutdown_function(
                /** @throws \RuntimeException */ static function () use ($exporter, $logger): void {
                    if (
                        Context::storage()->scope() !== null
                        || $logger->messages() !== []
                        || $exporter->getSpans() !== []
                    ) {
                        throw new \RuntimeException(
                            'shutdown must detach in order, without exporting unfinished spans',
                        );
                    }
                },
            );
            exit(0);
        });
    },
);
