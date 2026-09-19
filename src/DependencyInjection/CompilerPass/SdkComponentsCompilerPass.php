<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\SdkComponentIds;
use Nmspaced\TelemetryWeaver\DependencyInjection\SdkComponentRules;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\CustomOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MeterProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricView;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpProtocol;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TraceDecisions;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TracerProviderFactory;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\IdGeneratorInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Puts an application's own transport factory, exporter or provider where the bundle's would go.
 *
 * `config/services/sdk.php` describes the bundle's pipeline; this pass replaces only the links
 * the configuration names. A pass rather than a branch in the extension, because the
 * application's services are not defined yet when the extension loads — and an id that names no
 * service, or a service of the wrong kind, has to fail the compile rather than the first request.
 *
 * What stays around a replaced link is the same whoever built it: an exporter goes through
 * `ResilientExporters` (metrics also through `RequestMetricPolicy`), a provider through
 * `ProviderRegistry`, and a transport factory sits under both. Everything inside the replaced
 * link is the application's.
 */
final readonly class SdkComponentsCompilerPass implements CompilerPassInterface
{
    private const array PROVIDERS = [
        'traces' => TracerProviderInterface::class,
        'metrics' => MeterProviderInterface::class,
        'logs' => LoggerProviderInterface::class,
    ];

    /** @throws InvalidArgumentException when a configured id names no service or a service of the wrong kind */
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('open_telemetry.enabled')) {
            return;
        }

        SdkComponentRules::rejectIgnoredKeys($container, \array_keys(self::PROVIDERS));

        self::transports($container);
        self::traceDecisions($container);
        self::metricViews($container);

        foreach (self::PROVIDERS as $signal => $interface) {
            $provider = SdkComponentIds::reference(
                $container,
                'open_telemetry.sdk.' . $signal . '.provider',
                $interface,
            );
            if ($provider !== null) {
                $container->setAlias('open_telemetry.' . $signal . '.provider', (string) $provider);
            }
        }

        self::exporter($container, 'traces', SpanExporterInterface::class, [
            new Reference(ResilientExporters::class),
            'spans',
        ]);
        self::exporter($container, 'metrics', MetricExporterInterface::class, [
            new Reference(MetricExporterFactory::class),
            'adopt',
        ]);
        self::exporter($container, 'logs', LogRecordExporterInterface::class, [
            new Reference(ResilientExporters::class),
            'logs',
        ]);
    }

    /**
     * The views the meter provider is built with.
     *
     * @throws InvalidArgumentException
     */
    private static function metricViews(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MeterProviderFactory::class)) {
            return;
        }

        $views = SdkComponentIds::references($container, 'open_telemetry.sdk.metrics.views', MetricView::class);
        if ($views === []) {
            return;
        }

        $container->getDefinition(MeterProviderFactory::class)->setArgument('$views', new IteratorArgument($views));
    }

    /**
     * The sampler, the id generator and the extra span processors of the traces pipeline.
     *
     * These go into the bundle's own `TracerProviderFactory` rather than replacing anything
     * around it: an application that needs a per-route sampler or X-Ray-shaped trace ids used
     * to have to supply the whole provider, and lost the non-auto-flushing batch processor,
     * the boundary budget and the export gate along with it.
     *
     * The processors are an `IteratorArgument` so that naming one does not build it on every
     * request that never touches tracing.
     *
     * @throws InvalidArgumentException
     */
    private static function traceDecisions(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TracerProviderFactory::class)) {
            return;
        }

        $sampler = SdkComponentIds::reference($container, 'open_telemetry.sdk.traces.sampler', SamplerInterface::class);
        $idGenerator = SdkComponentIds::reference(
            $container,
            'open_telemetry.sdk.traces.id_generator',
            IdGeneratorInterface::class,
        );
        $processors = SdkComponentIds::references(
            $container,
            'open_telemetry.sdk.traces.span_processors',
            SpanProcessorInterface::class,
        );

        if ($sampler === null && $idGenerator === null && $processors === []) {
            return;
        }

        $container
            ->register(TraceDecisions::class, TraceDecisions::class)
            ->setArguments([$sampler, $idGenerator, new IteratorArgument($processors)]);

        $container
            ->getDefinition(TracerProviderFactory::class)
            ->setArgument('$decisions', new Reference(TraceDecisions::class));
    }

    /**
     * The application's factories sit in front of the bundle's transports, which keep every
     * protocol family the configuration does not name.
     *
     * @throws InvalidArgumentException
     */
    private static function transports(ContainerBuilder $container): void
    {
        $factories = [];
        foreach (OtlpProtocol::FAMILIES as $family) {
            $factory = SdkComponentIds::reference(
                $container,
                'open_telemetry.sdk.otlp.transport_factories.' . $family,
                TransportFactoryInterface::class,
            );
            if ($factory !== null) {
                $factories[$family] = $factory;
            }
        }

        SdkComponentRules::rejectIgnoredTransportSettings($container, $factories);

        if ($factories === []) {
            return;
        }

        $container
            ->register(CustomOtlpTransports::class, CustomOtlpTransports::class)
            ->setArguments([$factories, new Reference(BudgetedOtlpTransports::class)]);
        $container->setAlias(OtlpTransports::class, CustomOtlpTransports::class);
    }

    /**
     * The consumed exporter id is the interface itself; the configured exporter replaces the
     * factory behind it, and still arrives wrapped.
     *
     * @param class-string $interface
     * @param array{Reference, non-empty-string} $factory
     *
     * @throws InvalidArgumentException
     */
    private static function exporter(
        ContainerBuilder $container,
        string $signal,
        string $interface,
        array $factory,
    ): void {
        $exporter = SdkComponentIds::reference($container, 'open_telemetry.sdk.' . $signal . '.exporter', $interface);
        if ($exporter === null) {
            return;
        }

        $container->register($interface, $interface)->setFactory($factory)->setArguments([$exporter]);
    }
}
