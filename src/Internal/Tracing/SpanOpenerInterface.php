<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * @internal
 */
interface SpanOpenerInterface
{
    /**
     * @param non-empty-string $name
     */
    public function open(string $name, SpanOptions $options): OwnedSpan;
}
