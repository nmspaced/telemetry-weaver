<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * How a span sits among other traces: what it descends from, and what it is merely related to.
 *
 * The three travel together because they are one question answered in parts, and the opener
 * already resolves them as a unit — a parent that came from a carrier usually implies an
 * ambient span worth linking, and a link is only meaningful against the parent that was
 * chosen. Spread across a span description they read as three unrelated flags.
 *
 * @internal
 */
final readonly class TraceRelations
{
    /**
     * @param IncomingTrace|null $parent null continues whatever is already running; an
     *                                   incoming trace replaces it, and an invalid one starts
     *                                   a new trace rather than inheriting
     * @param list<IncomingTrace> $links traces this span is related to without descending from
     * @param bool $linkActiveSpan also link whatever span is running when this one opens
     */
    private function __construct(
        public ?IncomingTrace $parent,
        public array $links,
        public bool $linkActiveSpan,
    ) {}

    /**
     * Nothing stated yet: the span will descend from whatever is already running.
     */
    public static function ambient(): self
    {
        return new self(null, [], false);
    }

    /**
     * The span continues a trace that arrived from another process.
     */
    public static function continuing(IncomingTrace $parent): self
    {
        return new self($parent, [], false);
    }

    public function from(?IncomingTrace $parent): self
    {
        return $parent === null ? $this : new self($parent, $this->links, $this->linkActiveSpan);
    }

    public function linkedTo(IncomingTrace $trace): self
    {
        if (!$trace->isValid()) {
            return $this;
        }

        return new self($this->parent, [...$this->links, $trace], $this->linkActiveSpan);
    }

    public function linkedToActiveSpan(): self
    {
        return new self($this->parent, $this->links, true);
    }
}
