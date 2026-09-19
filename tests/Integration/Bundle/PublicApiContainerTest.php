<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PublicApiContainerTest extends ContainerTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function enabled(): iterable
    {
        yield 'enabled' => [true];
        yield 'disabled' => [false];
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('enabled')]
    public function publicContractsAutowireIntoAnOptimizedContainer(bool $enabled): void
    {
        $bundle = new TelemetryWeaverBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.runtime_mode.worker', 1);
        $container->setParameter('kernel.runtime_mode.web', true);
        $container->setParameter('kernel.build_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.debug', false);
        $container->register('logger', RecordingLogger::class);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), ['enabled' => $enabled]);

        $bundle->build($container);
        $container->register(PublicApiConsumer::class)->setAutowired(true)->setPublic(true);
        $container->compile();

        self::assertFalse(
            $container->has(Telemetry::class),
            'The API is autowirable, not a public service locator entry.',
        );
        self::assertFalse($container->has(TelemetryFactory::class));
        $consumer = $container->get(PublicApiConsumer::class);
        self::assertSame(42, $consumer->telemetry->trace('application', static fn(): int => 42));
        self::assertSame(7, $consumer->factory->scope('library')->trace('library', static fn(): int => 7));
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function applicationAndLibraryScopesShareTheExistingProvider(): void
    {
        $exporter = new InMemoryExporter();
        $container = $this->compile(configure: static function (ContainerBuilder $container) use ($exporter): void {
            $container->register(SpanExporterInterface::class)->setSynthetic(true);
            $container->set(SpanExporterInterface::class, $exporter);
        });
        $telemetry = $container->get(Telemetry::class);
        $library = $container->get(TelemetryFactory::class)->scope('acme/library', '2.0');
        $telemetry->trace(
            'parent',
            /** @throws \Throwable */
            static fn(): bool => $library->trace('child', static fn(): bool => true),
        );
        $container->get(TracerProviderInterface::class)->forceFlush();
        $spans = \array_values($exporter->getSpans());
        self::assertContainsOnlyInstancesOf(ImmutableSpan::class, $spans);
        self::assertCount(2, $spans);
        $childSpan = $spans[0] ?? Assert::fail('missing child span');
        $parentSpan = $spans[1] ?? Assert::fail('missing parent span');
        self::assertSame('acme/library', $childSpan->getInstrumentationScope()->getName());
        self::assertSame('2.0', $childSpan->getInstrumentationScope()->getVersion());
        self::assertSame('app', $parentSpan->getInstrumentationScope()->getName());
        self::assertSame($parentSpan->getContext()->getSpanId(), $childSpan->getParentContext()->getSpanId());
        self::assertEquals($childSpan->getResource(), $parentSpan->getResource());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function factoryCannotReenableGloballyDisabledTracing(): void
    {
        $container = $this->compile(['traces' => ['enabled' => false]]);
        $telemetry = $container->get(TelemetryFactory::class)->scope('custom');
        $operation = $telemetry->operation('disabled')->start();
        self::assertNull($operation->span()->spanId());
        $operation->finish();
    }
}
