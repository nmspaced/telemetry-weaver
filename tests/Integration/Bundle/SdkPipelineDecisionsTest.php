<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SdkComponentsCompilerPass;
use Nmspaced\TelemetryWeaver\DependencyInjection\SdkComponentIds;
use Nmspaced\TelemetryWeaver\DependencyInjection\SdkComponentRules;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientTracesExporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricView;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TraceDecisions;
use Nmspaced\TelemetryWeaver\Tests\Fake\NameBasedSampler;
use Nmspaced\TelemetryWeaver\Tests\Fake\NamedTraceIdGenerator;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingMetricExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingSpanProcessor;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\View\SelectionCriteria\InstrumentNameCriteria;
use OpenTelemetry\SDK\Metrics\View\ViewTemplate;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * The decisions inside the bundle's own providers — sampling, trace ids, extra span processors,
 * metric views — as opposed to replacing a link of the pipeline wholesale, which is
 * {@see SdkComponentOverridesTest}.
 *
 * What each test has to show is that the decision takes effect *and* that everything the
 * bundle builds around it is still there. Replacing the whole provider was the only way to
 * reach these before, and it gave up the non-auto-flushing batch processor, the boundary budget
 * and the export gate along with them.
 */
#[CoversClass(SdkComponentsCompilerPass::class)]
#[CoversClass(TraceDecisions::class)]
#[CoversClass(MetricView::class)]
#[CoversClass(SdkComponentIds::class)]
#[CoversClass(SdkComponentRules::class)]
final class SdkPipelineDecisionsTest extends ContainerTestCase
{
    /**
     * The three trace decisions no OTEL_* variable can express. They go into the bundle's own
     * provider, so what has to be shown is that they take effect *and* that the batch
     * processor built around them is still the one exporting.
     *
     * @throws \Throwable
     */
    #[Test]
    public function anApplicationsSamplerIdGeneratorAndProcessorsGoIntoTheBundlesProvider(): void
    {
        $_SERVER[Variables::OTEL_TRACES_EXPORTER] = 'memory';

        $container = $this->compile([
            'sdk' => [
                'traces' => [
                    'sampler' => 'app.sampler',
                    'id_generator' => 'app.ids',
                    'span_processors' => ['app.processor'],
                ],
            ],
        ], configure: static function (ContainerBuilder $container): void {
            $container
                ->register('app.sampler', NameBasedSampler::class)
                ->setArguments([['dropped']])
                ->setPublic(true);
            $container->register('app.ids', NamedTraceIdGenerator::class)->setPublic(true);
            $container->register('app.processor', RecordingSpanProcessor::class)->setPublic(true);
        });

        $tracers = $container->get(TracerProviderInterface::class);
        self::assertInstanceOf(TracerProviderInterface::class, $tracers);
        $tracer = $tracers->getTracer('test');

        $tracer->spanBuilder('kept')->startSpan()->end();
        $tracer->spanBuilder('dropped')->startSpan()->end();

        $processor = $container->get('app.processor');
        self::assertInstanceOf(RecordingSpanProcessor::class, $processor);
        self::assertSame(['kept'], $processor->started, 'the sampler dropped the other span');
        self::assertSame(['kept'], $processor->ended, 'the processor sees the span before it is queued');

        $exporter = $container->get(SpanExporterInterface::class);
        self::assertInstanceOf(ResilientTracesExporter::class, $exporter, 'the bundle still wraps the exporter');
    }

    /** @throws \Throwable */
    #[Test]
    public function theConfiguredIdGeneratorShapesTheTraceId(): void
    {
        $_SERVER[Variables::OTEL_TRACES_EXPORTER] = 'memory';

        $container = $this->compile([
            'sdk' => ['traces' => ['id_generator' => 'app.ids']],
        ], configure: static function (ContainerBuilder $container): void {
            $container->register('app.ids', NamedTraceIdGenerator::class)->setPublic(true);
        });

        $tracers = $container->get(TracerProviderInterface::class);
        self::assertInstanceOf(TracerProviderInterface::class, $tracers);
        $span = $tracers->getTracer('test')->spanBuilder('root')->startSpan();
        $traceId = $span->getContext()->getTraceId();
        $span->end();

        self::assertStringStartsWith('abcdef01', $traceId);
    }

    /**
     * A view is how an attribute whose cardinality is unbounded gets cut at the source. The
     * assertion is on the exported data point, not on the container: a view that is registered
     * but never consulted looks identical from the outside.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aConfiguredViewReachesTheMeterProvider(): void
    {
        $container = $this->compile([
            'sdk' => [
                'metrics' => ['exporter' => 'app.metrics', 'views' => ['app.view']],
            ],
        ], configure: static function (ContainerBuilder $container): void {
            $container->register('app.metrics', RecordingMetricExporter::class)->setPublic(true);
            $container
                ->register('app.view', MetricView::class)
                ->setArguments([
                    new InstrumentNameCriteria('app.requests'),
                    ViewTemplate::create()->withAttributeKeys(['kept']),
                ]);
        });

        $meters = $container->get(MeterProviderInterface::class);
        self::assertInstanceOf(MeterProviderInterface::class, $meters);
        $meters->getMeter('test')->createCounter('app.requests')->add(1, ['kept' => 'yes', 'dropped' => 'no']);
        $meters->forceFlush();

        $exporter = $container->get('app.metrics');
        self::assertInstanceOf(RecordingMetricExporter::class, $exporter);
        $metric = $exporter->metrics[0] ?? self::fail('nothing was exported');

        self::assertSame(
            ['kept' => 'yes'],
            MetricPoints::first($metric)->attributes->toArray(),
            'the view dropped the unlisted attribute key',
        );
    }

    #[Test]
    public function aViewNextToAProviderIsRejected(): void
    {
        $this->assertCompileFails(
            InvalidArgumentException::class,
            'sdk.metrics.views is ignored when sdk.metrics.provider is set',
            ['sdk' => ['metrics' => ['provider' => 'app.meters', 'views' => ['app.view']]]],
        );
    }

    #[Test]
    public function aTraceDecisionNextToAProviderIsRejected(): void
    {
        $this->assertCompileFails(
            InvalidArgumentException::class,
            'sdk.traces.sampler is ignored when sdk.traces.provider is set',
            ['sdk' => ['traces' => ['provider' => 'app.tracers', 'sampler' => 'app.sampler']]],
        );
    }

    #[Test]
    public function extraSpanProcessorsNextToAProviderAreRejected(): void
    {
        $this->assertCompileFails(
            InvalidArgumentException::class,
            'sdk.traces.span_processors is ignored when sdk.traces.provider is set',
            ['sdk' => ['traces' => ['provider' => 'app.tracers', 'span_processors' => ['app.processor']]]],
        );
    }

    #[Test]
    public function aSpanProcessorOfTheWrongKindFailsTheCompile(): void
    {
        $this->assertCompileFails(
            InvalidArgumentException::class,
            'open_telemetry.sdk.traces.span_processors[0] names "app.wrong"',
            ['sdk' => ['traces' => ['span_processors' => ['app.wrong']]]],
        );
    }

    /**
     * @param class-string<\Throwable> $type
     * @param array<string, mixed> $config
     */
    private function assertCompileFails(string $type, string $message, array $config): void
    {
        try {
            $this->compile($config);
        } catch (\Throwable $throwable) {
            self::assertInstanceOf($type, $throwable, $throwable->getMessage());
            self::assertStringContainsString($message, $throwable->getMessage());

            return;
        }

        self::fail('The compile must fail with: ' . $message);
    }
}
