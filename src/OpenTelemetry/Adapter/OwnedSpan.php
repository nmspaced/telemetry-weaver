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
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ScopeInterface;

// @mago-expect lint:too-many-methods — SpanOwner port plus accessors for the opener
/**
 * @internal
 *
 * Owns one SDK span and its optional activation; the OpenTelemetry side of `SpanOwner`.
 * A finished owner releases both.
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

    private bool $abandoned = false;

    /** The scopes stacked above the activation that belong to this owner; null unless it confines them. */
    private ?ScopeConfinement $confinement = null;

    /**
     * Re-activates the context in its original storage; null when the owner cannot be re-entered.
     *
     * @var (\Closure(): ScopeInterface)|null
     */
    private ?\Closure $reentry = null;

    private readonly SpanContextInterface $spanContext;

    /**
     * Released on finish: a context holds its span, and a worker must not retain it.
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
            OwnedActivations::register($activation, $owner);
        }

        return $owner;
    }

    /**
     * Owns a span that was never activated, or whose activation failed.
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
     * The trace measurements for this span belong to. Kept after `detach()`.
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

    /**
     * Lets `attach()` re-activate the context in the storage it was first activated in.
     */
    public function reenterableIn(ContextStorageInterface $storage, ContextInterface $context): self
    {
        $this->reentry = static fn(): ScopeInterface => $storage->attach($context);

        return $this;
    }

    #[\Override]
    public function attach(): void
    {
        if ($this->activation !== null || $this->reentry === null) {
            return;
        }

        try {
            $activation = ($this->reentry)();
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter?->report('Context activation failed', $this->name, $throwable);

            return;
        }

        $this->activation = $activation;
        OwnedActivations::register($activation, $this);
    }

    /**
     * Makes the scopes later stacked above the activation part of this owner: `detach()` releases
     * them and `finish()` abandons the owners among them. Applies only while the activation is the
     * storage's top scope, so a storage that hands out other scope objects is left alone.
     */
    public function confining(ContextStorageInterface $storage): self
    {
        $this->confinement = ScopeConfinement::above(
            $this->activation,
            $storage,
            $this->instrumentationFailureReporter,
            $this->name,
        );

        return $this;
    }

    #[\Override]
    public function detach(): void
    {
        $activation = $this->activation;

        if ($activation === null) {
            return;
        }

        $this->confinement?->release();
        $this->activation = null;
        OwnedActivations::forget($activation);

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
            $this->confinement?->abandon();
        } finally {
            $this->end();
        }
    }

    /** Finishes on behalf of the confining owner, before the operation itself did. */
    public function abandon(): void
    {
        if ($this->finished) {
            return;
        }

        $this->abandoned = true;
        $this->finish();
    }

    #[\Override]
    public function isAbandoned(): bool
    {
        return $this->abandoned;
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
        $this->detach();
    }

    private function end(): void
    {
        $span = $this->span;
        $this->span = null;

        $this->correlation = null;
        $this->reentry = null;
        $this->confinement = null;

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
