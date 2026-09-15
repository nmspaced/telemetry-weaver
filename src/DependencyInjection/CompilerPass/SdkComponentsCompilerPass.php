<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\SdkComponentIds;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\OpenTelemetry\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\CustomOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\MetricExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\OtlpProtocol;
use Nmspaced\TelemetryWeaver\OpenTelemetry\OtlpTransports;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
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

        SdkComponentIds::rejectIgnoredKeys($container, \array_keys(self::PROVIDERS));

        self::transports($container);

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

        SdkComponentIds::rejectIgnoredTransportSettings($container, $factories);

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
