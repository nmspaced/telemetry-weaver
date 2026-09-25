<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\OpenTelemetry\SdkDiagnostics;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The bundle boots from a container compiled like a kernel compiles it, with private services
 * removed or inlined.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(TelemetryWeaverBundle::class)]
#[CoversClass(SdkDiagnostics::class)]
final class PrivateServicesBootTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function bootClaimsGlobalsAndDiagnosticsWhenServicesArePrivate(): void
    {
        $bundle = new TelemetryWeaverBundle();
        $container = $this->compile(exposeAll: false);
        $bundle->setContainer($container);
        $bundle->boot();

        self::assertTrue(LoggerHolder::isSet(), 'the SDK diagnostics reach the application logger');
        self::assertNotInstanceOf(NoopTracerProvider::class, Globals::tracerProvider());
    }
}
