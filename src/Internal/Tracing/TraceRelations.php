<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * Where a span sits among traces: its parent and its links.
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

    /** Descends from whatever is already running. */
    public static function ambient(): self
    {
        return new self(null, [], false);
    }

    /** Continues a trace from another process. */
    public static function continuing(IncomingTrace $parent): self
    {
        return new self($parent, [], false);
    }

    public function from(?IncomingTrace $parent): self
    {
        if ($parent === null) {
            return $this;
        }

        return new self($parent, $this->links, $this->linkActiveSpan);
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
