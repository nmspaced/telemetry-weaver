<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ScopeInterface;

/**
 * The scopes stacked above one activation, released together with it: the package's own owners
 * detach themselves and are abandoned later, and any other scope, such as one activated directly
 * through OpenTelemetry and never detached, is detached as it is.
 *
 * @internal
 */
final class ScopeConfinement
{
    /**
     * Owners released so far, innermost first.
     *
     * @var list<\WeakReference<OwnedSpan>>
     */
    private array $released = [];

    /**
     * @param \Fiber<mixed, mixed, mixed, mixed>|null $fiber the fiber whose context stack holds the activation
     * @param non-empty-string $name the confining operation, for diagnostics
     */
    private function __construct(
        private readonly ScopeInterface $activation,
        private readonly ContextStorageInterface $storage,
        private readonly ?\Fiber $fiber,
        private readonly ?InstrumentationFailureReporter $reporter,
        private readonly string $name,
    ) {}

    /**
     * Null unless the activation is the storage's top scope, so a storage that hands out other
     * scope objects is never unwound.
     *
     * @param non-empty-string $name
     */
    public static function above(
        ?ScopeInterface $activation,
        ContextStorageInterface $storage,
        ?InstrumentationFailureReporter $reporter,
        string $name,
    ): ?self {
        if ($activation === null || $storage->scope() !== $activation) {
            return null;
        }

        return new self($activation, $storage, \Fiber::getCurrent(), $reporter, $name);
    }

    /**
     * Detaches everything above the activation, innermost first. Only the fiber that activated it
     * sees its stack, so any other fiber releases nothing.
     */
    public function release(): void
    {
        if ($this->fiber !== \Fiber::getCurrent()) {
            return;
        }

        try {
            while (($inner = $this->storage->scope()) !== null && $inner !== $this->activation) {
                $this->detach($inner);

                if ($this->storage->scope() === $inner) {
                    $this->reporter?->report('inner context scope stayed active', $this->name);

                    return;
                }
            }
        } catch (\Throwable $throwable) {
            $this->reporter?->report('inner context release failed', $this->name, $throwable);
        }
    }

    /** Ends the owners released so far that their operations have not finished. */
    public function abandon(): void
    {
        $released = $this->released;
        $this->released = [];

        foreach ($released as $owner) {
            $owner->get()?->abandon();
        }
    }

    private function detach(ScopeInterface $inner): void
    {
        $owner = OwnedActivations::ownerOf($inner);

        if ($owner === null) {
            $inner->detach();

            return;
        }

        $owner->detach();
        $this->released[] = \WeakReference::create($owner);
    }
}
