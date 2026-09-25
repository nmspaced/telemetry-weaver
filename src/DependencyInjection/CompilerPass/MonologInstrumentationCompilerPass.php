<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Monolog\Logger;
use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\DependencyInjection\SignalSwitch;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\LogExportPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\OtelLogHandler;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\TraceContextProcessor;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\LogCorrelation;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Registers log correlation and OTLP log export, each behind its own switch. */
final readonly class MonologInstrumentationCompilerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)->requires('monolog/monolog', Logger::class);

        if ($gate->isClosed()) {
            return;
        }

        $correlated = SignalSwitch::on($container, 'open_telemetry.logs.correlation.enabled');

        if ($correlated) {
            $container
                ->register(TraceContextProcessor::class, TraceContextProcessor::class)
                ->setArgument('$trace', new Reference(ActiveTrace::class))
                ->addTag('monolog.processor');
        }

        if (!SignalSwitch::on($container, 'open_telemetry.logs.export.enabled')) {
            return;
        }

        $container
            ->register(LogExportPolicy::class, LogExportPolicy::class)
            ->setArgument('$level', $container->getParameter('open_telemetry.logs.export.level'))
            ->setArgument(
                '$excludedChannels',
                $container->getParameter('open_telemetry.logs.export.excluded_channels'),
            );

        $container
            ->register(OtelLogHandler::class, OtelLogHandler::class)
            ->setArgument('$loggerProvider', new Reference('open_telemetry.logger_provider'))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class))
            ->setArgument('$policy', new Reference(LogExportPolicy::class))
            ->setArgument('$correlation', $correlated ? new Reference(LogCorrelation::class) : null);
    }
}
