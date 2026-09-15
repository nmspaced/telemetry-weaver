<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver;

use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\CacheInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\ConsoleInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\DoctrineInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\HttpClientInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\MailerInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\MessengerInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\MonologInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SchedulerInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SdkComponentsCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SerializerInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\ConfiguredLogExport;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationParameters;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\OtelLogHandler;
use Nmspaced\TelemetryWeaver\OpenTelemetry\GlobalsRegistrar;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

// @mago-expect analysis:missing-constructor — Symfony initializes name/path lazily and supplies the container through setContainer()
final class TelemetryWeaverBundle extends AbstractBundle
{
    protected string $extensionAlias = 'open_telemetry';

    #[\Override]
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    #[\Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    /**
     * Puts the OTel handler into Monolog's own stack.
     *
     * Through MonologBundle's `type: service` handler rather than by pushing onto
     * `monolog.logger_prototype`: the prototype is MonologBundle's internal shape and
     * the ordering against its own `LoggerChannelPass` would be ours to keep right,
     * while the handler entry is the documented way for another bundle to add one.
     *
     * Prepending is conditional, because a handler entry naming a service that does not
     * exist is a compile error rather than a disabled feature.
     */
    #[\Override]
    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        if (
            !$container->hasExtension('monolog')
            || !ConfiguredLogExport::isRequested($this->getContainerExtension(), $container)
        ) {
            return;
        }

        $container->prependExtensionConfig('monolog', [
            'handlers' => [
                'open_telemetry' => ['type' => 'service', 'id' => OtelLogHandler::class],
            ],
        ]);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        /**
         * @var array{
         *      enabled: bool,
         *      runtime: array{request_metrics: array{mode: 'disabled'|'delta'}},
         *      sdk: array{
         *          resource_attributes: array<string, bool|int|float|string|null>,
         *          exporter_otlp_headers: array<string, bool|int|float|string|null>,
         *          export: array{
         *              flush_timeout_ms: positive-int,
         *              failure_cooldown_ms: int<0, max>,
         *              max_retries: int<0, max>,
         *              retry_delay_ms: int<0, max>
         *          },
         *          otlp: array{transport_factories: array{grpc: bool|int|float|string|null, http: bool|int|float|string|null}},
         *          traces: array{provider: bool|int|float|string|null, exporter: bool|int|float|string|null},
         *          metrics: array{provider: bool|int|float|string|null, exporter: bool|int|float|string|null},
         *          logs: array{provider: bool|int|float|string|null, exporter: bool|int|float|string|null}
         *      },
         *      scope: array{
         *          name: non-empty-string,
         *          version: non-empty-string|null,
         *          schema_url: non-empty-string
         *      },
         *      traces: array{enabled: bool},
         *      metrics: array{
         *          enabled: bool,
         *          flush_interval_ms: positive-int|null
         *      },
         *      instrumentation: array<non-empty-string, array<non-empty-string, mixed>>,
         *      logs: array{
         *          correlation: array{enabled: bool},
         *          export: array{
         *              enabled: bool,
         *              level: non-empty-string,
         *              excluded_channels: list<non-empty-string>
         *          }
         *      },
         *      diagnostics: array{
         *          enabled: bool,
         *          detailed_per_process: int<0, max>,
         *          min_interval_seconds: float
         *      }
         *  } $config
         */

        if (!$config['enabled']) {
            $configurator->import('../config/disabled.php');

            return;
        }

        $parameters = $configurator->parameters();

        $parameters
            ->set('open_telemetry.enabled', $config['enabled'])
            ->set('open_telemetry.runtime.request_metrics.mode', $config['runtime']['request_metrics']['mode'])
            ->set('open_telemetry.sdk.resource_attributes', $config['sdk']['resource_attributes'])
            ->set('open_telemetry.sdk.exporter_otlp_headers', $config['sdk']['exporter_otlp_headers'])
            ->set('open_telemetry.sdk.export.flush_timeout_ms', $config['sdk']['export']['flush_timeout_ms'])
            ->set('open_telemetry.sdk.export.failure_cooldown_ms', $config['sdk']['export']['failure_cooldown_ms'])
            ->set('open_telemetry.sdk.export.max_retries', $config['sdk']['export']['max_retries'])
            ->set('open_telemetry.sdk.export.retry_delay_ms', $config['sdk']['export']['retry_delay_ms'])
            ->set(
                'open_telemetry.sdk.otlp.transport_factories.grpc',
                $config['sdk']['otlp']['transport_factories']['grpc'],
            )
            ->set(
                'open_telemetry.sdk.otlp.transport_factories.http',
                $config['sdk']['otlp']['transport_factories']['http'],
            )
            ->set('open_telemetry.sdk.traces.provider', $config['sdk']['traces']['provider'])
            ->set('open_telemetry.sdk.traces.exporter', $config['sdk']['traces']['exporter'])
            ->set('open_telemetry.sdk.metrics.provider', $config['sdk']['metrics']['provider'])
            ->set('open_telemetry.sdk.metrics.exporter', $config['sdk']['metrics']['exporter'])
            ->set('open_telemetry.sdk.logs.provider', $config['sdk']['logs']['provider'])
            ->set('open_telemetry.sdk.logs.exporter', $config['sdk']['logs']['exporter'])
            ->set('open_telemetry.scope.name', $config['scope']['name'])
            ->set('open_telemetry.scope.version', $config['scope']['version'])
            ->set('open_telemetry.scope.schema_url', $config['scope']['schema_url'])
            ->set('open_telemetry.traces.enabled', $config['traces']['enabled'])
            ->set('open_telemetry.metrics.enabled', $config['metrics']['enabled'])
            ->set('open_telemetry.metrics.flush_interval_ms', $config['metrics']['flush_interval_ms'])
            ->set('open_telemetry.logs.correlation.enabled', $config['logs']['correlation']['enabled'])
            ->set('open_telemetry.logs.export.enabled', $config['logs']['export']['enabled'])
            ->set('open_telemetry.logs.export.level', $config['logs']['export']['level'])
            ->set('open_telemetry.logs.export.excluded_channels', $config['logs']['export']['excluded_channels'])
            ->set('open_telemetry.diagnostics.enabled', $config['diagnostics']['enabled'])
            ->set('open_telemetry.diagnostics.detailed_per_process', $config['diagnostics']['detailed_per_process'])
            ->set('open_telemetry.diagnostics.min_interval_seconds', $config['diagnostics']['min_interval_seconds']);

        InstrumentationParameters::flatten($parameters, $config['instrumentation']);

        $configurator->import('../config/services.php');
    }

    /**
     * Claims Globals for the container's providers.
     *
     * Here rather than in the extension: initializers are process state, and
     * the container is only a description of services until the kernel boots.
     * Doing it at boot also puts this bundle's initializer after the SDK
     * autoloader's, which is what makes it the one that wins.
     */
    #[\Override]
    public function boot(): void
    {
        parent::boot();

        $container = $this->container ?? null;

        if ($container === null || !$container->hasParameter('open_telemetry.enabled')) {
            return;
        }

        if ($container->getParameter('open_telemetry.enabled') !== true) {
            return;
        }

        $this->claimGlobals($container);
    }

    /**
     * Best-effort: losing Globals costs other packages' instrumentation a pipeline, and
     * failing the boot would cost the application everything.
     */
    private function claimGlobals(ContainerInterface $container): void
    {
        try {
            $registrar = $container->get(GlobalsRegistrar::REGISTRAR_ID);
        } catch (\Throwable) {
            return;
        }

        if ($registrar instanceof GlobalsRegistrar) {
            $registrar->register();
        }
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new SdkComponentsCompilerPass());
        $container->addCompilerPass(new MonologInstrumentationCompilerPass(), priority: 10);
        $container->addCompilerPass(new ConsoleInstrumentationCompilerPass(), priority: -20);
        $container->addCompilerPass(new SchedulerInstrumentationCompilerPass(), priority: -20);
        $container->addCompilerPass(new MailerInstrumentationCompilerPass(), priority: -20);
        $container->addCompilerPass(new HttpClientInstrumentationCompilerPass(), priority: -20);
        $container->addCompilerPass(new CacheInstrumentationCompilerPass(), priority: -20);
        $container->addCompilerPass(new SerializerInstrumentationCompilerPass(), priority: -20);
        $container->addCompilerPass(new DoctrineInstrumentationCompilerPass(), priority: 10);
        $container->addCompilerPass(new MessengerInstrumentationCompilerPass(), priority: 10);
    }
}
