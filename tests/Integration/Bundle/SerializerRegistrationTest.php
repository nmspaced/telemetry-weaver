<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Serializer\TraceableSerializer;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Serializer\DataCollector\SerializerDataCollector;
use Symfony\Component\Serializer\Debug\TraceableSerializer as ProfilerSerializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

final class SerializerRegistrationTest extends ContainerTestCase
{
    private static function serializer(ContainerBuilder $container): void
    {
        $container
            ->register('serializer', Serializer::class)
            ->setArguments([[new Definition(PropertyNormalizer::class)], [new Definition(JsonEncoder::class)]]);
    }

    /** @throws \Throwable */
    #[Test]
    public function theDefaultSerializerIsWrapped(): void
    {
        $container = $this->compile(configure: self::serializer(...));

        self::assertInstanceOf(TraceableSerializer::class, $container->get('serializer'));
    }

    /** @throws \Throwable */
    #[Test]
    public function theProfilersOwnDecoratorDoesNotBreakTheWrapping(): void
    {
        $container = $this->compile(configure: static function (ContainerBuilder $container): void {
            self::serializer($container);
            $container->register('serializer.data_collector', SerializerDataCollector::class);
            $container
                ->register('debug.serializer', ProfilerSerializer::class)
                ->setDecoratedService('serializer')
                ->setArguments([
                    new Reference('debug.serializer.inner'),
                    new Reference('serializer.data_collector'),
                    'default',
                ]);
        });

        $serializer = $container->get('serializer');

        self::assertInstanceOf(ProfilerSerializer::class, $serializer);
        self::assertInstanceOf(SerializerInterface::class, $serializer);
        self::assertTrue($container->hasDefinition('serializer.open_telemetry'));
        self::assertSame('{"value":1}', $serializer->serialize(new class {
            public int $value = 1;
        }, 'json'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anIncompleteDecoratorInsideThisOneMeansNoDecorationRatherThanAFatal(): void
    {
        $container = $this->compile(configure: static function (ContainerBuilder $container): void {
            self::serializer($container);
            $container->register('serializer.data_collector', SerializerDataCollector::class);
            $container
                ->register('partial.serializer', ProfilerSerializer::class)
                ->setDecoratedService('serializer', null, 64)
                ->setArguments([
                    new Reference('partial.serializer.inner'),
                    new Reference('serializer.data_collector'),
                    'default',
                ]);
        });

        self::assertFalse($container->hasDefinition('serializer.open_telemetry'));
        self::assertInstanceOf(ProfilerSerializer::class, $container->get('serializer'));
    }
}
