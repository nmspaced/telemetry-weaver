<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\ConfiguredBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * The boundaries a component measures with: the semantic-conventions defaults, or the ones an
 * application with a tighter SLO configured.
 */
#[CoversClass(ConfiguredBuckets::class)]
final class DurationBucketsConfigurationTest extends ContainerTestCase
{
    /** @return iterable<string, array{string, DefaultBuckets}> */
    public static function components(): iterable
    {
        yield 'http_server' => ['http_server', DefaultBuckets::Http];
        yield 'http_client' => ['http_client', DefaultBuckets::Http];
        yield 'doctrine' => ['doctrine', DefaultBuckets::Database];
        yield 'cache' => ['cache', DefaultBuckets::Cache];
        yield 'messenger' => ['messenger', DefaultBuckets::Messaging];
        yield 'serializer' => ['serializer', DefaultBuckets::Serializer];
        yield 'mailer' => ['mailer', DefaultBuckets::Mail];
        yield 'console' => ['console', DefaultBuckets::Command];
        yield 'scheduler' => ['scheduler', DefaultBuckets::ScheduledTask];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('components')]
    public function aComponentWithoutConfiguredBoundariesKeepsItsPreset(string $component, DefaultBuckets $preset): void
    {
        $container = $this->compile();
        $buckets = $container->get('open_telemetry.' . $component . '.buckets');

        self::assertInstanceOf(OperationBuckets::class, $buckets);
        self::assertSame($preset->boundaries(), $buckets->boundaries());
        self::assertSame($preset->unit(), $buckets->unit());
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('components')]
    public function configuredBoundariesReplaceThePreset(string $component, DefaultBuckets $preset): void
    {
        $container = $this->compile([
            'instrumentation' => [$component => ['duration_buckets' => [0.001, 0.002, 0.004]]],
        ]);

        $buckets = $container->get('open_telemetry.' . $component . '.buckets');

        self::assertInstanceOf(OperationBuckets::class, $buckets);
        self::assertSame([0.001, 0.002, 0.004], $buckets->boundaries());
        self::assertSame($preset->unit(), $buckets->unit(), "the unit is not the application's to change");
    }

    /** @throws \Throwable */
    #[Test]
    public function componentsSharingAPresetAreConfiguredIndependently(): void
    {
        $container = $this->compile([
            'instrumentation' => ['http_server' => ['duration_buckets' => [0.5, 1]]],
        ]);

        $server = $container->get('open_telemetry.http_server.buckets');
        $client = $container->get('open_telemetry.http_client.buckets');

        self::assertInstanceOf(OperationBuckets::class, $server);
        self::assertInstanceOf(OperationBuckets::class, $client);
        self::assertSame([0.5, 1], $server->boundaries());
        self::assertSame(DefaultBuckets::Http->boundaries(), $client->boundaries());
    }

    /** @return iterable<string, array{list<float>}> */
    public static function rejected(): iterable
    {
        yield 'unordered' => [[0.1, 0.05, 1.0]];
        yield 'repeated' => [[0.1, 0.1]];
        yield 'zero' => [[0.0, 0.1]];
        yield 'negative' => [[-1.0, 0.1]];
    }

    /**
     * @param list<float> $boundaries
     *
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('rejected')]
    public function unusableBoundariesFailTheCompile(array $boundaries): void
    {
        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessageMatches('/strictly increasing and greater than zero/');

        $this->compile(['instrumentation' => ['doctrine' => ['duration_buckets' => $boundaries]]]);
    }

    /** @throws \Throwable */
    #[Test]
    public function theRuntimeComponentHasNoBoundariesToConfigure(): void
    {
        self::expectException(InvalidConfigurationException::class);

        $this->compile(['instrumentation' => ['runtime' => ['duration_buckets' => [1.0]]]]);
    }
}
