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
 * The retry settings are the one export knob with no OTEL_* variable behind it, so the
 * only thing that can carry them is the container — and a parameter that never reaches
 * a service is the defect this repository has the most of.
 *
 * Arguments are read by index rather than by name: the compiler resolves named
 * arguments to positional ones, so `$maxRetries` no longer exists by the time a
 * compiled definition is inspected.
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
            // Argument 1: the reporter comes first, the transports second. The compile resolves
            // the OtlpTransports alias to the service behind it.
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
        // The clock between them keeps its default, so the compiler leaves both as PHP named arguments.
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
