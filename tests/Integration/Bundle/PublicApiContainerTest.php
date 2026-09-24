<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;
use Nmspaced\TelemetryWeaver\Api\TraceContext;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\Context\Context;
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
        self::assertFalse($container->has(ActiveTrace::class));
        $consumer = $container->get(PublicApiConsumer::class);
        self::assertSame(42, $consumer->telemetry->trace('application', static fn(): int => 42));
        self::assertSame(7, $consumer->factory->scope('library')->trace('library', static fn(): int => 7));
        self::assertNull($consumer->activeTrace->current(), 'nothing is running outside an operation');
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('enabled')]
    public function externalContextRemainsVisibleWithTracingOffButNotWithTheBundleDisabled(bool $enabled): void
    {
        $container = $this->compile(['enabled' => $enabled, 'traces' => ['enabled' => false]]);
        $external = SpanContext::createFromRemoteParent(\str_repeat('a', 32), \str_repeat('b', 16));
        $scope = Context::storage()->attach(Context::getRoot()->withContextValue(OtelSpan::wrap($external)));

        try {
            $activeTrace = $container->get(ActiveTrace::class);
            $container->get(Telemetry::class)->trace('suppressed', static function (Span $span) use (
                $activeTrace,
                $enabled,
                $external,
            ): void {
                self::assertNull($span->spanId());
                $current = $activeTrace->current();

                if (!$enabled) {
                    self::assertNull($current);

                    return;
                }

                self::assertNotNull($current);
                self::assertSame($external->getTraceId(), $current->traceId);
                self::assertSame($external->getSpanId(), $current->spanId);
                self::assertFalse($current->sampled());
            });
        } finally {
            $scope->detach();
        }
    }

    /**
     * The point of the port: code with no operation in scope can still name the trace it
     * is running inside, and the ids it reads are the ids of the span that is current.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theActiveTraceNamesTheSpanTheCallerIsInside(): void
    {
        $container = $this->compile();
        $telemetry = $container->get(Telemetry::class);
        $ambient = $container->get(ActiveTrace::class);

        [$traceId, $spanId, $current] = $telemetry->trace(
            'operation',
            /**
             * @return array{non-empty-string|null, non-empty-string|null, TraceContext|null}
             *
             * @throws \Throwable
             */
            static fn(Span $span): array => [$span->traceId(), $span->spanId(), $ambient->current()],
        );
        self::assertNotNull($current);
        self::assertSame($traceId, $current->traceId);
        self::assertSame($spanId, $current->spanId);
        self::assertTrue($current->sampled(), 'the default sampler sets the sampled flag');
        self::assertNull($ambient->current(), 'and the operation released it on the way out');
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
