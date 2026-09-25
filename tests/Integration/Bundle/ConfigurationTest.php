<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ContextOnlyOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SemConv\Version;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class ConfigurationTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theBundleIsEnabledByDefault(): void
    {
        self::assertTrue($this->compile()->getParameter('open_telemetry.enabled'));
    }

    /** @throws \Throwable */
    #[Test]
    public function disablingTheBundleWiresNothing(): void
    {
        $container = $this->compile(['enabled' => false]);

        self::assertFalse($container->hasParameter('open_telemetry.enabled'));
        self::assertFalse($container->has(TracerInterface::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function disabledHttpInjectsNoopSignals(): void
    {
        $container = $this->compile([
            'instrumentation' => ['http_server' => ['traces' => false, 'metrics' => false]],
        ]);

        self::assertInstanceOf(ContextOnlyOpener::class, $container->get('open_telemetry.http_server.span_opener'));
        self::assertInstanceOf(NoopMeter::class, $container->get('open_telemetry.http_server.meter'));
    }

    /** @throws \Throwable */
    #[Test]
    public function theScopeSchemaIsTheSemanticConventionsBaseline(): void
    {
        self::assertSame(
            Version::VERSION_1_44_0->url(),
            $this->compile()->getParameter('open_telemetry.scope.schema_url'),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function anInvalidExportIntervalIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->compile(['metrics' => ['flush_interval_ms' => 0]]);
    }

    /** @throws \Throwable */
    #[Test]
    public function unknownSdkOptionsAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->compile(['sdk' => ['autoload' => true]]);
    }

    /** @throws \Throwable */
    #[Test]
    public function anExplicitCommandListReplacesTheDefaultsButNeverTheWorkers(): void
    {
        $container = $this->compile([
            'instrumentation' => ['console' => ['excluded_commands' => ['app:import']]],
        ]);

        $excluded = $container->getParameter('open_telemetry.instrumentation.console.excluded_commands');

        self::assertSame(['messenger:consume', 'messenger:consume-messages', 'app:import'], $excluded);
    }

    /** @throws \Throwable */
    #[Test]
    public function theWorkersAreExcludedWithoutBeingAskedForAndAreNotRepeatedWhenTheyAre(): void
    {
        $defaults = $this->compile()->getParameter('open_telemetry.instrumentation.console.excluded_commands');

        self::assertIsIterable($defaults);
        self::assertContains('messenger:consume', $defaults);
        self::assertContains('messenger:consume-messages', $defaults);
        self::assertContains('cache:clear', $defaults);

        $repeated = $this->compile([
            'instrumentation' => ['console' => ['excluded_commands' => ['messenger:consume', 'app:import']]],
        ])->getParameter('open_telemetry.instrumentation.console.excluded_commands');

        self::assertSame(['messenger:consume', 'messenger:consume-messages', 'app:import'], $repeated);
    }
}
