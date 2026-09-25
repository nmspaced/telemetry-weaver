<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetricsSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Cache-pool-selection behaviour (which pools get wrapped, alias sharing, ambient-context
 * preservation) lives in {@see CachePoolSelectionSignalTest}.
 */
final class SignalConfigurationTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function exampleAndTestOverrideCompile(): void
    {
        /**
         * @var array{parameters: array<string, mixed>, open_telemetry: array<string, mixed>, 'when@test': array{open_telemetry: array<string, mixed>}} $example
         */
        $example = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/example_config.yaml');
        $parameters = static function (ContainerBuilder $container) use ($example): void {
            foreach ($example['parameters'] as $name => $value) {
                /** @var \UnitEnum|array<array-key, mixed>|string|int|float|bool|null $value */
                $container->setParameter($name, $value);
            }

            $container->setParameter('app.name', 'test');
            $container->setParameter('kernel.environment', 'test');
        };
        $container = $this->compile($example['open_telemetry'], configure: $parameters);
        self::assertNull($container->getParameter('open_telemetry.metrics.flush_interval_ms'));
        $tracePolicy = $container->get('open_telemetry.http_server.traces.request_policy');
        $metricPolicy = $container->get('open_telemetry.http_server.metrics.request_policy');
        self::assertInstanceOf(RequestPolicy::class, $tracePolicy);
        self::assertInstanceOf(RequestPolicy::class, $metricPolicy);
        self::assertTrue($tracePolicy->isExcluded(Request::create('/health')));
        self::assertTrue($metricPolicy->isExcluded(Request::create('/health')));
        self::assertTrue($tracePolicy->isExcluded(Request::create('/_profiler/token')));
        self::assertTrue($metricPolicy->isExcluded(Request::create('/_profiler/token')));
        $disabled = $this->compile(
            \array_replace_recursive($example['open_telemetry'], $example['when@test']['open_telemetry']),
            configure: $parameters,
        );
        self::assertFalse($disabled->has(HttpServerTracingSubscriber::class));
        self::assertFalse($disabled->has(HttpServerMetricsSubscriber::class));
        self::assertFalse($disabled->hasParameter('open_telemetry.enabled'));
    }

    /** @throws \Throwable */
    #[Test]
    public function httpExclusionsAreIndependentInBothDirections(): void
    {
        $container = $this->compile([
            'instrumentation' => [
                'http_server' => [
                    'traces' => ['excluded_paths' => ['/trace-only']],
                    'metrics' => ['excluded_paths' => ['/metric-only']],
                ],
            ],
        ]);
        $trace = $container->get('open_telemetry.http_server.traces.request_policy');
        $metric = $container->get('open_telemetry.http_server.metrics.request_policy');
        self::assertInstanceOf(RequestPolicy::class, $trace);
        self::assertInstanceOf(RequestPolicy::class, $metric);
        foreach ([
            '/trace-only' => [true, false],
            '/metric-only' => [false, true],
            '/other' => [false, false],
        ] as $path => [$traceExcluded, $metricExcluded]) {
            self::assertSame($traceExcluded, $trace->isExcluded(Request::create($path)));
            self::assertSame($metricExcluded, $metric->isExcluded(Request::create($path)));
        }
    }
}
