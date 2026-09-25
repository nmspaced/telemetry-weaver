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
 * Wires the application's own transport factories, exporters, providers and trace decisions
 * into the bundle's pipeline.
 *
 * A compiler pass so that an unknown or wrong-kind service id fails the compile. Replaced
 * links keep the bundle's wrappers around them.
 */
final readonly class SdkComponentsCompilerPass implements CompilerPassInterface
{
    private const array PROVIDERS = [
        'traces' => TracerProviderInterface::class,
        'metrics' => MeterProviderInterface::class,
        'logs' => LoggerProviderInterface::class,
    ];

    /**
     * @throws InvalidArgumentException when a configured id names no service or a service of the wrong kind
     */
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
                \sprintf('open_telemetry.sdk.%s.provider', $signal),
                $interface,
            );
            if ($provider !== null) {
                $container->setAlias(\sprintf('open_telemetry.%s.provider', $signal), (string) $provider);
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

    /** @throws InvalidArgumentException */
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
     * The sampler, id generator and extra span processors, passed into the bundle's own
     * `TracerProviderFactory`. Processors are lazy, so an unused one is never built.
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
     * The application's factories go in front of the bundle's transports.
     *
     * @throws InvalidArgumentException
     */
    private static function transports(ContainerBuilder $container): void
    {
        $factories = [];
        foreach (OtlpProtocol::FAMILIES as $family) {
            $factory = SdkComponentIds::reference(
                $container,
                \sprintf('open_telemetry.sdk.otlp.transport_factories.%s', $family),
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
     * Replaces the factory behind an exporter interface; the result is still wrapped.
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
        $exporter = SdkComponentIds::reference(
            $container,
            \sprintf('open_telemetry.sdk.%s.exporter', $signal),
            $interface,
        );
        if ($exporter === null) {
            return;
        }

        $container->register($interface, $interface)->setFactory($factory)->setArguments([$exporter]);
    }
}
