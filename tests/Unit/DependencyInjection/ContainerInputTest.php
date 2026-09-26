<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\DependencyInjection;

use Nmspaced\TelemetryWeaver\DependencyInjection\CachePoolSelection;
use Nmspaced\TelemetryWeaver\DependencyInjection\ConfigParameters;
use Nmspaced\TelemetryWeaver\DependencyInjection\ConfiguredLogExport;
use Nmspaced\TelemetryWeaver\DependencyInjection\DefinitionClass;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationParameters;
use Nmspaced\TelemetryWeaver\DependencyInjection\SdkComponentIds;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ParametersConfigurator;

/** Container parameters and definitions the bundle reads while compiling, in shapes it must not trust. */
#[CoversClass(CachePoolSelection::class)]
#[CoversClass(SdkComponentIds::class)]
#[CoversClass(DefinitionClass::class)]
#[CoversClass(ConfiguredLogExport::class)]
#[CoversClass(InstrumentationGate::class)]
#[CoversClass(ConfigParameters::class)]
#[CoversClass(InstrumentationParameters::class)]
final class ContainerInputTest extends TestCase
{
    #[Test]
    public function withoutAPoolListNoPoolIsSelected(): void
    {
        self::assertFalse(CachePoolSelection::fromContainer(new ContainerBuilder())->includes('cache.app'));
    }

    #[Test]
    public function aPoolListThatIsNotAListIsRejected(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('open_telemetry.instrumentation.cache.pools', 'cache.app');

        $this->expectException(\LogicException::class);

        CachePoolSelection::fromContainer($container);
    }

    /** @throws \Throwable */
    #[Test]
    public function componentIdsThatAreNotAListNameNothing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.processors', 'not a list');

        self::assertSame([], SdkComponentIds::references($container, 'app.processors', SpanProcessorInterface::class));
        self::assertNull(SdkComponentIds::reference($container, 'app.processors', SpanProcessorInterface::class, 0));
    }

    /** @throws \Throwable */
    #[Test]
    public function anIdThatIsNotAStringIsNamedByItsType(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.sampler', 42);

        $rejected = null;
        try {
            SdkComponentIds::reference($container, 'app.sampler', SpanProcessorInterface::class);
        } catch (InvalidArgumentException $exception) {
            $rejected = $exception;
        }

        self::assertStringContainsString('names "int"', $rejected?->getMessage() ?? '');
    }

    #[Test]
    public function aClassParameterThatIsNotAClassNameLeavesTheClassUnknown(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.processor.class', 42);

        self::assertNull(DefinitionClass::of($container, new Definition('%app.processor.class%')));
    }

    #[Test]
    public function logExportIsNotRequestedWithoutAConfigurableExtensionOrAValidConfiguration(): void
    {
        $container = new ContainerBuilder();
        $extension = new TelemetryWeaverBundle()->getContainerExtension();
        $container->prependExtensionConfig('open_telemetry', ['no_such_option' => true]);

        self::assertFalse(ConfiguredLogExport::isRequested(null, $container));
        self::assertFalse(ConfiguredLogExport::isRequested($extension, $container), 'the extension reports the error');
    }

    #[Test]
    public function theGateFollowsTheBundleSwitch(): void
    {
        $enabled = new ContainerBuilder();
        $enabled->setParameter('open_telemetry.enabled', true);
        $disabled = new ContainerBuilder();
        $disabled->setParameter('open_telemetry.enabled', false);

        self::assertTrue(InstrumentationGate::bundle($enabled)->isOpen());
        self::assertFalse(InstrumentationGate::bundle($disabled)->isOpen());
    }

    #[Test]
    public function onlyNamedKeysBecomeParameters(): void
    {
        $container = new ContainerBuilder();

        ConfigParameters::flatten(new ParametersConfigurator($container), 'open_telemetry', [
            'logs' => ['level' => 'info', 7 => 'positional'],
        ]);

        self::assertSame('info', $container->getParameter('open_telemetry.logs.level'));
        self::assertFalse($container->hasParameter('open_telemetry.logs.7'));
    }

    #[Test]
    public function aComponentWithoutSignalOverridesGetsOnlyItsOwnParameters(): void
    {
        $container = new ContainerBuilder();

        InstrumentationParameters::flatten(new ParametersConfigurator($container), ['cache' => ['pools' => ['*']]]);

        self::assertSame(['*'], $container->getParameter('open_telemetry.instrumentation.cache.pools'));
        self::assertFalse($container->hasParameter('open_telemetry.instrumentation.cache.traces.pools'));
    }
}
