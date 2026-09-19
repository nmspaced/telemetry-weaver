<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationTimer;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\SDK\Metrics\Data\Exemplar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which span a recorded duration is correlated with.
 *
 * This is the reason `DurationTimer` captures a context at all, and until now nothing
 * asserted it: the fake histogram the timer tests use accepts the context argument and
 * drops it, so a refactor that stopped passing one would have gone through green. The
 * measurement here is read back through a real `MeterProvider`, whose default exemplar
 * filter is `WithSampledTraceExemplarFilter` — it reads the span out of the context the
 * recording supplied, which is exactly the contract under test.
 *
 * The second case is the one that constrains the design. An HTTP server span releases its
 * activation at one point in the request and is finished at another, so the duration is
 * recorded when the operation's span is no longer current. Whatever replaces the ambient
 * `Context::getCurrent()` in the timer has to keep the exemplar pointing at the operation's
 * own span, not at whatever happens to be active at `finish()`.
 */
#[CoversClass(DurationTimer::class)]
final class DurationExemplarTest extends PublicTelemetryTestCase
{
    /**
     * @throws \Throwable
     */
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

    /**
     * @throws \Throwable
     */
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

    /**
     * The filter is real, not incidentally satisfied: with the span suppressed there is no
     * sampled span behind the recording and the measurement carries no exemplar at all.
     *
     * @throws \Throwable
     */
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

    /**
     * A suppressed span is not a suppressed trace.
     *
     * Doctrine under `only_with_parent` and an excluded HttpClient host both record their
     * duration while opening no span of their own. That duration belongs to whatever the
     * caller was doing, and the exemplar has to say so — otherwise switching a component's
     * spans off silently takes its metrics out of the trace as well.
     *
     * @throws \Throwable
     */
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
