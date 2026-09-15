<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * The example files are documentation, and documentation that does not compile is
 * worse than none: it is read as a promise. Both are fed to the real tree.
 */
final class ExampleConfigTest extends ContainerTestCase
{
    /** @return iterable<string, array{string}> */
    public static function files(): iterable
    {
        yield 'example' => [__DIR__ . '/../../../config/example_config.yaml'];
        yield 'minimal' => [__DIR__ . '/../../../config/minimal_config.yaml'];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('files')]
    public function theDocumentedConfigurationIsAcceptedByTheTree(string $file): void
    {
        /** @var array{open_telemetry: array<string, mixed>} $parsed */
        $parsed = Yaml::parseFile($file);

        $container = $this->compile($parsed['open_telemetry'], configure: static function (ContainerBuilder $container): void {
            $container->setParameter('app.name', 'probe');
            $container->setParameter('kernel.environment', 'test');
        });

        self::assertTrue($container->hasParameter('open_telemetry.enabled'));
    }

    /**
     * Every key the example shows has to reach a container parameter. A key that
     * validates and then goes nowhere is the defect this repository has had the most of.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theKeysTheExampleShowsReachTheContainer(): void
    {
        /** @var array{open_telemetry: array<string, mixed>} $parsed */
        $parsed = Yaml::parseFile(__DIR__ . '/../../../config/example_config.yaml');

        $container = $this->compile($parsed['open_telemetry'], configure: static function (ContainerBuilder $container): void {
            $container->setParameter('app.name', 'probe');
            $container->setParameter('kernel.environment', 'test');
        });

        foreach ([
            'open_telemetry.instrumentation.http_server.traces',
            'open_telemetry.instrumentation.http_server.excluded_paths',
            'open_telemetry.instrumentation.http_server.traces.excluded_paths',
            'open_telemetry.instrumentation.http_server.record_client_ip',
            'open_telemetry.instrumentation.doctrine.query_text',
            'open_telemetry.instrumentation.doctrine.only_with_parent',
            'open_telemetry.instrumentation.doctrine.transactions',
            'open_telemetry.instrumentation.cache.excluded_pools',
            'open_telemetry.instrumentation.console.excluded_commands',
            'open_telemetry.metrics.flush_interval_ms',
            'open_telemetry.scope.name',
            'open_telemetry.sdk.export.max_retries',
            'open_telemetry.sdk.otlp.transport_factories.grpc',
            'open_telemetry.sdk.otlp.transport_factories.http',
            'open_telemetry.sdk.traces.provider',
            'open_telemetry.sdk.logs.exporter',
        ] as $parameter) {
            self::assertTrue($container->hasParameter($parameter), $parameter . ' never reaches the container');
        }
    }
}
