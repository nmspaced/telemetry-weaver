<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Whether a signal produces telemetry is decided by the object injected into it, never
 * by a flag it carries. These are the four signals that have their own switches, and
 * the container is the only place the switches are read.
 */
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
        self::assertInstanceOf(NoOpSpanOpener::class, $noTraces->get($this->opener($signal)));
        self::assertNotInstanceOf(NoopMeter::class, $noTraces->get($this->meter($signal)));

        $noMetrics = $this->compile(['instrumentation' => [$signal => ['metrics' => false]]]);
        self::assertInstanceOf(NoopMeter::class, $noMetrics->get($this->meter($signal)));
        self::assertInstanceOf(SpanOpener::class, $noMetrics->get($this->opener($signal)));
    }

    /**
     * The trap this guards: an instrumentation wired for its metrics half would keep
     * producing spans after tracing was switched off wholesale.
     *
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('signals')]
    public function theGlobalSwitchOutranksTheSignalsOwn(string $signal): void
    {
        $noTraces = $this->compile(['traces' => ['enabled' => false]]);
        self::assertInstanceOf(NoOpSpanOpener::class, $noTraces->get($this->opener($signal)));

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
