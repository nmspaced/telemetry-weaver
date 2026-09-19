<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SignalFlusher;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderRegistry::class)]
final class ProviderRegistryTest extends TestCase
{
    #[Test]
    public function aProviderIsHandedBackAfterItIsRegistered(): void
    {
        $registry = self::registry();
        $provider = new TracerProvider();

        self::assertSame($provider, $registry->traces($provider));
        self::assertSame(['traces'], self::signals($registry));
    }

    #[Test]
    public function aNoopProviderIsHandedBackWithNothingToFlush(): void
    {
        $registry = self::registry();
        $tracers = new NoopTracerProvider();
        $meters = new NoopMeterProvider();
        $loggers = new NoopLoggerProvider();

        self::assertSame($tracers, $registry->traces($tracers));
        self::assertSame($meters, $registry->metrics($meters));
        self::assertSame($loggers, $registry->logs($loggers));
        self::assertSame([], self::signals($registry));
    }

    private static function registry(): ProviderRegistry
    {
        return new ProviderRegistry(Flushers::openGate(), new ExportFailureReporter(new RecordingLogger()));
    }

    /** @return list<string> */
    private static function signals(ProviderRegistry $registry): array
    {
        return \array_map(
            static fn(SignalFlusher $signal): string => $signal->signal(),
            \iterator_to_array($registry->ordered(), false),
        );
    }
}
