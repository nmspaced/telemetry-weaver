<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOwner;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;

// @mago-expect lint:too-many-methods — the SpanOwner port plus the two accessors the opener
// and its tests need; every method is one line of delegation and splitting them would put an
// owner's lifecycle in two places.
/**
 * @internal Mutable owner of one span and its optional activation; a finished owner retains neither.
 *
 * The OpenTelemetry half of {@see SpanOwner}: `SpanInterface`, `ScopeInterface` and
 * `SpanContextInterface` reach exactly this far and no further. `view()` narrows the port's
 * return type to the concrete view, which is what lets the adapter keep the two internal
 * methods the recording path needs without widening the interface for everyone else.
 */
final class OwnedSpan implements SpanOwner
{
    /**
     * @var array<int, non-empty-string>
     */
    private const array FLAG_MEANINGS = [
        ScopeInterface::DETACHED => 'context scope was already detached',
        ScopeInterface::INACTIVE => 'context scope was not active',
        ScopeInterface::MISMATCH => 'context scope closed out of order',
    ];

    private ?SpanInterface $span;

    private readonly SpanView $view;

    private ?ScopeInterface $activation;

    private bool $finished = false;

    private readonly SpanContextInterface $spanContext;

    /**
     * Not readonly, and released with the span.
     *
     * An OpenTelemetry context holds the span it was created for, so an owner that kept
     * its correlation would keep the SDK span reachable for as long as the owner itself
     * lived — precisely the retention a long-running worker cannot afford, and the reason
     * the package hands out views rather than spans everywhere else. Anything that still
     * needs the correlation has taken its own reference by now: a measurement captures it
     * when it starts, and `finish()` records before it ends the span.
     */
    private ?TraceCorrelation $correlation;

    /**
     * @param non-empty-string $name
     */
    private function __construct(
        private readonly string $name,
        SpanInterface $span,
        ?ScopeInterface $activation = null,
        private readonly ?InstrumentationFailureReporter $instrumentationFailureReporter = null,
        ?TraceCorrelation $correlation = null,
    ) {
        $this->correlation = $correlation;
        $this->span = $span;
        $this->view = SpanView::borrowed($span, $this->instrumentationFailureReporter);
        $this->activation = $activation;

        try {
            $this->spanContext = $span->getContext();
        } catch (\Throwable $throwable) {
            $this->spanContext = SpanContext::getInvalid();
            $this->finish();
            $this->instrumentationFailureReporter?->report('span context read failed', $this->name, $throwable);
        }
    }

    /**
     * @param non-empty-string $name
     */
    public static function activated(
        string $name,
        SpanInterface $span,
        ScopeInterface $activation,
        InstrumentationFailureReporter $reporter,
        ?TraceCorrelation $correlation = null,
    ): self {
        $owner = new self($name, $span, $activation, $reporter, $correlation);

        if ($owner->activation !== null) {
            ShutdownScopeCleanup::register($owner);
        }

        return $owner;
    }

    /**
     * Owns an unactivated span, including one whose activation failed.
     *
     * @param non-empty-string $name
     */
    public static function detached(string $name, SpanInterface $span, InstrumentationFailureReporter $reporter): self
    {
        return new self($name, $span, null, $reporter);
    }

    #[\Override]
    public function view(): SpanView
    {
        return $this->view;
    }

    /** @return non-empty-string|null */
    #[\Override]
    public function errorType(): ?string
    {
        return $this->view->errorType();
    }

    #[\Override]
    public function rememberErrorType(array $attributes): void
    {
        $this->view->rememberErrorType($attributes);
    }

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    public function spanContext(): SpanContextInterface
    {
        return $this->spanContext;
    }

    /**
     * The trace a measurement taken for this span belongs to.
     *
     * Survives `detach()` on purpose: the activation is what makes the span ambient, and
     * an operation that has stopped being ambient has not stopped being the one being
     * measured.
     */
    #[\Override]
    public function correlation(): ?TraceCorrelation
    {
        return $this->correlation;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function enrich(\Closure $change): void
    {
        $span = $this->span;

        try {
            if ($span === null || !$span->isRecording()) {
                return;
            }

            $change($span);
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter?->report('span enrichment failed', $this->name, $throwable);
        }
    }

    #[\Override]
    public function detach(): void
    {
        if ($this->activation === null) {
            return;
        }

        $activation = $this->activation;
        $this->activation = null;
        ShutdownScopeCleanup::forget($this);

        try {
            $this->reportFlags($activation->detach());
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter?->report('detach failed', $this->name, $throwable);
        }
    }

    #[\Override]
    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        try {
            $this->detach();
        } finally {
            $this->end();
        }
    }

    /**
     * @param non-empty-string $name
     */
    public static function inert(string $name, ?TraceCorrelation $correlation = null): self
    {
        return new self($name, Span::getInvalid(), correlation: $correlation);
    }

    public function __destruct()
    {
        // exit can destroy call-stack locals before shutdown callbacks run. Release
        // only the scope: destructing abandoned work must not record success or export.
        $this->detach();
    }

    private function end(): void
    {
        $span = $this->span;
        $this->span = null;

        $this->correlation = null;

        $this->view->release();
        $this->finished = true;

        try {
            $span?->end();
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter?->report('span end failed', $this->name, $throwable);
        }
    }

    private function reportFlags(int $flags): void
    {
        foreach (self::FLAG_MEANINGS as $flag => $meaning) {
            if (($flags & $flag) === 0) {
                continue;
            }

            $this->instrumentationFailureReporter?->report($meaning, $this->name);
        }
    }
}
