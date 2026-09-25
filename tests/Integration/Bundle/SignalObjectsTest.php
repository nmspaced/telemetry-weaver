<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ContextOnlyOpener;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** Each signal is switched off by injecting a no-op object, never by a flag. */
final class SignalObjectsTest extends ContainerTestCase
{
    /** @return iterable<string, array{string}> */
    public static function signals(): iterable
    {
        yield 'http_server' => ['http_server'];
        yield 'cache' => ['cache'];
        yield 'serializer' => ['serializer'];
        yield 'doctrine' => ['doctrine'];
        yield 'messenger' => ['messenger'];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('signals')]
    public function aSignalIsRealUntilItsOwnKeyTurnsItOff(string $signal): void
    {
        $container = $this->compile();

        self::assertInstanceOf(SpanOpener::class, $container->get($this->opener($signal)));
        self::assertNotInstanceOf(NoopMeter::class, $container->get($this->meter($signal)));
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('signals')]
    public function itsOwnKeyTurnsOffOneHalfAndLeavesTheOther(string $signal): void
    {
        $noTraces = $this->compile(['instrumentation' => [$signal => ['traces' => false]]]);
        self::assertInstanceOf(ContextOnlyOpener::class, $noTraces->get($this->opener($signal)));
        self::assertNotInstanceOf(NoopMeter::class, $noTraces->get($this->meter($signal)));

        $noMetrics = $this->compile(['instrumentation' => [$signal => ['metrics' => false]]]);
        self::assertInstanceOf(NoopMeter::class, $noMetrics->get($this->meter($signal)));
        self::assertInstanceOf(SpanOpener::class, $noMetrics->get($this->opener($signal)));
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('signals')]
    public function theGlobalSwitchOutranksTheSignalsOwn(string $signal): void
    {
        $noTraces = $this->compile(['traces' => ['enabled' => false]]);
        self::assertInstanceOf(ContextOnlyOpener::class, $noTraces->get($this->opener($signal)));

        $noMetrics = $this->compile(['metrics' => ['enabled' => false]]);
        self::assertInstanceOf(NoopMeter::class, $noMetrics->get($this->meter($signal)));
    }

    private function opener(string $signal): string
    {
        return \sprintf('open_telemetry.%s.span_opener', $signal);
    }

    private function meter(string $signal): string
    {
        return \sprintf('open_telemetry.%s.meter', $signal);
    }
}
