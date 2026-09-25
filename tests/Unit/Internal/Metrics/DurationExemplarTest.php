<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationTimer;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\SDK\Metrics\Data\Exemplar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/** Which span a recorded duration is correlated with. */
#[CoversClass(DurationTimer::class)]
final class DurationExemplarTest extends PublicTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theExemplarNamesTheOperationsOwnSpanRatherThanItsParent(): void
    {
        $telemetry = $this->telemetry();
        $duration = $this->duration($telemetry);

        $parent = $telemetry->operation('parent')->start();
        $telemetry
            ->operation('measured')
            ->duration($duration)
            ->run(static fn(): null => null);
        $parent->finish();

        self::assertSame($this->spanIdOf('measured'), $this->exemplarSpanId());
    }

    /** @throws \Throwable */
    #[Test]
    public function theExemplarSurvivesAnActivationReleasedBeforeTheOperationFinishes(): void
    {
        $telemetry = $this->telemetry();
        $duration = $this->duration($telemetry);

        $operation = $telemetry
            ->boundary('measured')
            ->duration($duration)
            ->start();

        $operation->detach();
        self::assertNull($this->contextStorage->scope(), 'the operation is no longer the current span');

        $this->clock->advanceNanoseconds(1_000_000);
        $operation->finish();

        self::assertSame($this->spanIdOf('measured'), $this->exemplarSpanId());
    }

    /** @throws \Throwable */
    #[Test]
    public function aMeasurementWithoutASpanCarriesNoExemplar(): void
    {
        $telemetry = $this->telemetry(traces: false);
        $duration = $this->duration($telemetry);

        $telemetry
            ->operation('measured')
            ->duration($duration)
            ->run(static fn(): null => null);

        self::assertSame(1, $this->metricPoint()->count, 'the duration is still recorded');
        self::assertSame([], $this->exemplars());
    }

    /** @throws \Throwable */
    #[Test]
    public function aSuppressedOperationStillNamesTheTraceItRanInside(): void
    {
        $telemetry = $this->telemetry();
        $duration = $this->duration($telemetry);

        $caller = $telemetry->operation('caller')->start();
        $telemetry
            ->boundary('suppressed')
            ->withoutSpan()
            ->duration($duration)
            ->run(static fn(): null => null);
        $caller->finish();

        self::assertSame(['caller'], $this->exportedNames(), 'the suppressed operation exports no span');
        self::assertSame($this->spanIdOf('caller'), $this->exemplarSpanId());
    }

    /** @return list<Exemplar> */
    private function exemplars(): array
    {
        $exemplars = [];

        foreach ($this->metricPoint()->exemplars as $exemplar) {
            self::assertInstanceOf(Exemplar::class, $exemplar);
            $exemplars[] = $exemplar;
        }

        return $exemplars;
    }

    private function exemplarSpanId(): ?string
    {
        $exemplars = $this->exemplars();
        self::assertCount(1, $exemplars, 'the measurement carries exactly one exemplar');
        $exemplar = $exemplars[0] ?? self::fail('unreachable');

        return $exemplar->spanId;
    }

    /**
     * Read from the exported span rather than from the operation, so the assertion keeps
     * describing the same thing once the public API stops handing out span contexts.
     *
     * @param non-empty-string $name
     */
    private function spanIdOf(string $name): string
    {
        foreach ($this->exported() as $span) {
            if ($span->getName() === $name) {
                return $span->getContext()->getSpanId();
            }
        }

        self::fail(\sprintf('no span named "%s" was exported', $name));
    }
}
