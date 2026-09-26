<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\DependencyInjection;

use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\DoctrineInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\ConfiguredLogExport;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\QueryText;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\PhpFileRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateDump;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateProviderFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\Filesystem\Filesystem;

/** Build-time decisions made from values that did not come through the bundle's own validation. */
#[CoversClass(DoctrineInstrumentationCompilerPass::class)]
#[CoversClass(ConfiguredLogExport::class)]
#[CoversClass(RouteTemplateProviderFactory::class)]
final class CompilerInputTest extends TestCase
{
    private string $buildDir;

    /** @throws \Throwable */
    #[\Override]
    protected function setUp(): void
    {
        $this->buildDir = \sys_get_temp_dir() . '/telemetry-weaver-' . \bin2hex(\random_bytes(4));
    }

    /** @throws \Throwable */
    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->buildDir);
    }

    /** @throws \Throwable */
    #[Test]
    public function aQueryTextSettingThatIsNotTextFallsBackToSanitized(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.enabled', true);
        $container->setParameter('open_telemetry.traces.enabled', true);
        $container->setParameter('open_telemetry.instrumentation.doctrine.traces', true);
        $container->setParameter('open_telemetry.instrumentation.doctrine.query_text', true);

        new DoctrineInstrumentationCompilerPass()->process($container);

        self::assertSame(
            QueryText::Sanitized,
            $container->getDefinition(DoctrinePolicy::class)->getArgument('$queryText'),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function anExtensionWithoutAConfigurationRequestsNoLogExport(): void
    {
        $extension = new class implements ExtensionInterface, ConfigurationExtensionInterface {
            /** @param array<array-key, mixed> $config */
            #[\Override]
            public function getConfiguration(array $config, ContainerBuilder $container): null
            {
                return null;
            }

            #[\Override]
            public function load(array $configs, ContainerBuilder $container): void {}

            #[\Override]
            public function getAlias(): string
            {
                return 'open_telemetry';
            }
        };

        self::assertFalse(ConfiguredLogExport::isRequested($extension, new ContainerBuilder()));
    }

    /** @throws \Throwable */
    #[Test]
    public function inDebugAFreshDumpIsStillPreferredOverTheRouter(): void
    {
        new ConfigCache(RouteTemplateDump::pathIn($this->buildDir), true)->write(RouteTemplateDump::render([
            'orders' => '/orders/{id}',
        ]), []);

        $provider = RouteTemplateProviderFactory::create($this->buildDir, debug: true);

        self::assertInstanceOf(PhpFileRouteTemplateProvider::class, $provider);
        self::assertSame('/orders/{id}', $provider->resolve('orders'));
    }
}
