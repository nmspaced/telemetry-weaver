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
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SecurityInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SerializerInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\ConfigParameters;
use Nmspaced\TelemetryWeaver\DependencyInjection\ConfiguredLogExport;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationParameters;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\OtelLogHandler;
use Nmspaced\TelemetryWeaver\OpenTelemetry\GlobalsRegistrar;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SdkDiagnostics;
use Psr\Log\LoggerInterface;
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
     * Adds the OTLP log handler to Monolog's stack through MonologBundle's `type: service`
     * handler, and only when the handler service exists.
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
     * Flattens the validated configuration into `open_telemetry.*` parameters.
     *
     * @param array<array-key, mixed> $config
     */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        if (($config['enabled'] ?? true) !== true) {
            $configurator->import('../config/disabled.php');

            return;
        }

        $parameters = $configurator->parameters();

        ConfigParameters::flatten($parameters, 'open_telemetry', $config, except: ['instrumentation']);

        /** @var array<non-empty-string, array<non-empty-string, mixed>> $instrumentation */
        $instrumentation = $config['instrumentation'] ?? [];
        InstrumentationParameters::flatten($parameters, $instrumentation);

        $configurator->import('../config/services.php');
    }

    /**
     * Claims `Globals` and SDK diagnostics at boot, when the container's services exist.
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
        $this->routeSdkDiagnostics($container);
    }

    /**
     * Best-effort: losing SDK diagnostics must not fail the boot.
     */
    private function routeSdkDiagnostics(ContainerInterface $container): void
    {
        try {
            $logger = $container->get('open_telemetry.diagnostics.logger');
        } catch (\Throwable) {
            return;
        }

        if ($logger instanceof LoggerInterface) {
            SdkDiagnostics::install($logger);
        }
    }

    /**
     * Best-effort: losing `Globals` must not fail the boot.
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
        $container->addCompilerPass(new SecurityInstrumentationCompilerPass(), priority: -20);
        $container->addCompilerPass(new DoctrineInstrumentationCompilerPass(), priority: 10);
        $container->addCompilerPass(new MessengerInstrumentationCompilerPass(), priority: 10);
    }
}
