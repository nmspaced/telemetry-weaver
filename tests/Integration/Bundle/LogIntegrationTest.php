<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Monolog\Level;
use Monolog\LogRecord;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\OtelLogHandler;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\TraceContextProcessor;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

final class LogIntegrationTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function correlationIsOnByDefaultAndExportIsNot(): void
    {
        $container = $this->compile();

        self::assertTrue($container->hasDefinition(TraceContextProcessor::class));
        self::assertFalse($container->hasDefinition(OtelLogHandler::class));
    }

    /**
     * The switch used to write a parameter nobody read, so turning correlation off left
     * the processor tagged and running.
     *
     * @throws \Throwable
     */
    #[Test]
    public function switchingCorrelationOffRemovesTheProcessorRatherThanLeavingItTagged(): void
    {
        $container = $this->compile(['logs' => ['correlation' => ['enabled' => false]]]);

        self::assertFalse($container->hasDefinition(TraceContextProcessor::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function enablingExportWiresTheHandlerWithItsConfiguredLevelAndExclusions(): void
    {
        $container = $this->compile([
            'logs' => [
                'export' => ['enabled' => true, 'level' => 'error', 'excluded_channels' => ['event']],
            ],
        ]);

        $handler = $container->get(OtelLogHandler::class);
        self::assertInstanceOf(OtelLogHandler::class, $handler);

        // Asked of the built handler rather than of its definition: named arguments are
        // resolved to positions at compile time, so the definition no longer says.
        self::assertFalse($handler->isHandling(self::record(Level::Warning, 'app')), 'below the configured level');
        self::assertTrue($handler->isHandling(self::record(Level::Error, 'app')));
        self::assertFalse($handler->isHandling(self::record(Level::Error, 'event')), 'excluded channel');
    }

    /**
     * With OTEL_LOGS_EXPORTER=none the provider has nowhere to send, and it has to be a
     * no-op rather than a real provider: a real one would accept records into a batch
     * queue that nothing ever drains.
     *
     * @throws \Throwable
     */
    #[Test]
    public function anExporterlessConfigurationGetsANoopProviderAndAFlusherThatStillResolves(): void
    {
        $_SERVER['OTEL_LOGS_EXPORTER'] = 'none';

        try {
            $container = $this->compile(['logs' => ['export' => ['enabled' => true]]]);

            self::assertInstanceOf(NoopLoggerProvider::class, $container->get('open_telemetry.logger_provider'));
            self::assertInstanceOf(ProviderRegistry::class, $container->get(ProviderRegistry::class));
        } finally {
            unset($_SERVER['OTEL_LOGS_EXPORTER']);
        }
    }

    #[Test]
    public function theHandlerIsPrependedIntoMonologsStackOnlyWhenExportIsOn(): void
    {
        self::assertSame([], self::prependedHandlers(['logs' => ['export' => ['enabled' => false]]]));
        self::assertSame(
            ['open_telemetry' => ['type' => 'service', 'id' => OtelLogHandler::class]],
            self::prependedHandlers(['logs' => ['export' => ['enabled' => true]]]),
        );
        self::assertSame(
            [],
            self::prependedHandlers(['enabled' => false, 'logs' => ['export' => ['enabled' => true]]]),
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed> the handlers this bundle asked MonologBundle to add
     */
    private static function prependedHandlers(array $config): array
    {
        $bundle = new TelemetryWeaverBundle();
        $container = new ContainerBuilder();
        $container->registerExtension(self::monologExtension());

        $extension = $bundle->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);

        $extension->prepend($container);

        $handlers = [];

        foreach ($container->getExtensionConfig('monolog') as $prepended) {
            /** @var array{handlers?: array<string, mixed>} $prepended */
            $handlers += $prepended['handlers'] ?? [];
        }

        return $handlers;
    }

    private static function record(Level $level, string $channel): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), $channel, $level, 'probe');
    }

    private static function monologExtension(): ExtensionInterface
    {
        return new class implements ExtensionInterface {
            #[\Override]
            public function load(array $configs, ContainerBuilder $container): void {}

            #[\Override]
            public function getAlias(): string
            {
                return 'monolog';
            }
        };
    }
}
