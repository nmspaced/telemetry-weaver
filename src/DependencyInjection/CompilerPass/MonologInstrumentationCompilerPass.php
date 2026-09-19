<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Monolog\Logger;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\DependencyInjection\SignalSwitch;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\OtelLogHandler;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\TraceContextProcessor;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\ActiveTraceIdentity;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The two things this bundle does to application logs, each behind its own switch.
 *
 * Correlation writes the active trace and span id into every record, so a log line in
 * whatever the application already uses — a file, stdout, an ELK pipeline — can be
 * joined to the trace it belongs to. That is the cheap half and it is on by default.
 *
 * Export ships the records themselves through OTLP, and is off by default because it
 * adds a second destination for every line and needs somewhere to send them.
 *
 * A pass rather than entries in services.php, because both are conditional and services
 * .php has no way to ask: registering them there and removing them later would leave
 * `logs.correlation: false` meaning nothing at all, which is what it meant before.
 */
final readonly class MonologInstrumentationCompilerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)->requires('monolog/monolog', Logger::class);

        if ($gate->isClosed()) {
            return;
        }

        if (SignalSwitch::on($container, 'open_telemetry.logs.correlation.enabled')) {
            $container
                ->register(TraceContextProcessor::class, TraceContextProcessor::class)
                ->setArgument('$trace', new Reference(ActiveTraceIdentity::class))
                ->addTag('monolog.processor');
        }

        if (!SignalSwitch::on($container, 'open_telemetry.logs.export.enabled')) {
            return;
        }

        $container
            ->register(OtelLogHandler::class, OtelLogHandler::class)
            ->setArgument('$loggerProvider', new Reference('open_telemetry.logger_provider'))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class))
            ->setArgument('$level', $container->getParameter('open_telemetry.logs.export.level'))
            ->setArgument(
                '$excludedChannels',
                $container->getParameter('open_telemetry.logs.export.excluded_channels'),
            );
    }
}
