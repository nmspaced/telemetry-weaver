<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpTransportSettings;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Retry settings reach the export services through the container. Arguments are checked by index,
 * because compilation turns named arguments into positional ones.
 */
final class ExportRetryConfigurationTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function retriesAreOffByDefault(): void
    {
        $container = $this->compile();

        self::assertSame(0, $container->getParameter('open_telemetry.sdk.export.max_retries'));
        self::assertSame(0, $container->getDefinition(OtlpTransportSettings::class)->getArgument(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function theConfiguredValuesReachTheTransportFactory(): void
    {
        $container = $this->compile(['sdk' => ['export' => ['max_retries' => 2, 'retry_delay_ms' => 400]]]);

        $definition = $container->getDefinition(OtlpTransportSettings::class);
        self::assertSame(2, $definition->getArgument(0), 'max_retries');
        self::assertSame(400, $definition->getArgument(1), 'retry_delay_ms');
    }

    /** @throws \Throwable */
    #[Test]
    public function everySignalGetsTheSameTransportFactory(): void
    {
        $container = $this->compile();

        foreach (['SpanExporterFactory', 'MetricExporterFactory', 'LogRecordExporterFactory'] as $factory) {
            $id = 'Nmspaced\\TelemetryWeaver\\OpenTelemetry\\Sdk\\' . $factory;
            self::assertTrue($container->hasDefinition($id), $factory . ' is not registered');
            self::assertSame(
                BudgetedOtlpTransports::class,
                (string) $container->getDefinition($id)->getArgument(1),
                $factory . ' does not receive the configured transport factory',
            );
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function theCoordinatorAndTransportShareTheConfiguredBudget(): void
    {
        $container = $this->compile([
            'sdk' => ['export' => [
                'flush_timeout_ms' => 250,
                'failure_cooldown_ms' => 500,
            ]],
        ]);
        self::assertSame(
            ['timeoutMilliseconds' => 250, 'failureCooldownMilliseconds' => 500],
            $container->getDefinition(FlushBudget::class)->getArguments(),
        );
        self::assertSame(
            ExportGate::class,
            (string) $container->getDefinition(BudgetedOtlpTransports::class)->getArgument(0),
        );
        self::assertSame(
            FlushBudget::class,
            (string) $container->getDefinition(TelemetryFlusher::class)->getArgument(1),
        );
        self::assertSame(FlushBudget::class, (string) $container->getDefinition(ExportGate::class)->getArgument(0));
        self::assertSame(500, $container->getDefinition(ProviderRegistry::class)->getArgument(2));
    }

    /** @throws \Throwable */
    #[Test]
    public function aZeroBudgetIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->compile(['sdk' => ['export' => ['flush_timeout_ms' => 0]]]);
    }

    /** @throws \Throwable */
    #[Test]
    public function aNegativeCooldownIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->compile(['sdk' => ['export' => ['failure_cooldown_ms' => -1]]]);
    }
}
