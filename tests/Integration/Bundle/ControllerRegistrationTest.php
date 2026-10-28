<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\TraceableArgumentResolver;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\TraceableArgumentResolver as ProfilerArgumentResolver;
use Symfony\Component\Stopwatch\Stopwatch;

final class ControllerRegistrationTest extends ContainerTestCase
{
    private static function argumentResolver(ContainerBuilder $container): void
    {
        $container->register('argument_resolver', ArgumentResolver::class);
    }

    /** @throws \Throwable */
    #[Test]
    public function theCallIsWrappedAndArgumentResolutionDecoratedByDefault(): void
    {
        $container = $this->compile(configure: self::argumentResolver(...));

        self::assertTrue(
            $container->getDefinition(ControllerTelemetrySubscriber::class)->hasTag('kernel.event_subscriber'),
        );
        self::assertInstanceOf(TraceableArgumentResolver::class, $container->get('argument_resolver'));
    }

    /** @throws \Throwable */
    #[Test]
    public function theProfilersOwnDecoratorStaysOutermost(): void
    {
        $container = $this->compile(configure: static function (ContainerBuilder $container): void {
            self::argumentResolver($container);
            $container->register('debug.stopwatch', Stopwatch::class);
            $container
                ->register('debug.argument_resolver', ProfilerArgumentResolver::class)
                ->setDecoratedService('argument_resolver')
                ->setArguments([new Reference('debug.argument_resolver.inner'), new Reference('debug.stopwatch')]);
        });

        self::assertInstanceOf(ProfilerArgumentResolver::class, $container->get('argument_resolver'));
        self::assertTrue($container->hasDefinition('argument_resolver.open_telemetry'));
    }

    /** @throws \Throwable */
    #[Test]
    public function withoutAnArgumentResolverOnlyTheCallIsWrapped(): void
    {
        $container = $this->compile();

        self::assertTrue($container->hasDefinition(ControllerTelemetrySubscriber::class));
        self::assertFalse($container->hasDefinition('argument_resolver.open_telemetry'));
    }

    /** @throws \Throwable */
    #[Test]
    public function tracesOffRegistersNothing(): void
    {
        $container = $this->compile(
            ['instrumentation' => ['controller' => ['traces' => false]]],
            configure: self::argumentResolver(...),
        );

        self::assertFalse($container->hasDefinition(ControllerTelemetrySubscriber::class));
        self::assertInstanceOf(ArgumentResolver::class, $container->get('argument_resolver'));
    }

    /** @throws \Throwable */
    #[Test]
    public function theComponentHasNoMetricsSwitch(): void
    {
        self::assertFalse($this->compile()->hasParameter('open_telemetry.instrumentation.controller.metrics'));

        $this->expectException(InvalidConfigurationException::class);
        $this->compile(['instrumentation' => ['controller' => ['metrics' => true]]]);
    }

    /** @throws \Throwable */
    #[Test]
    public function aDisabledBundleRegistersNothing(): void
    {
        $container = $this->compile(['enabled' => false], configure: self::argumentResolver(...));

        self::assertFalse($container->hasDefinition(ControllerTelemetrySubscriber::class));
        self::assertInstanceOf(ArgumentResolver::class, $container->get('argument_resolver'));
    }
}
