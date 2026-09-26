<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionEntry;

/** Records the order in which entries are abandoned, and can fail doing so. */
final class RecordingEntry implements ExecutionEntry
{
    /** @param \ArrayObject<int, string> $abandoned */
    public function __construct(
        private readonly string $name,
        private readonly \ArrayObject $abandoned,
        private readonly bool $failing = false,
    ) {}

    #[\Override]
    public function complete(): void {}

    /** @throws \RuntimeException when failing */
    #[\Override]
    public function abandon(): void
    {
        $this->abandoned[] = $this->name;

        if ($this->failing) {
            throw new \RuntimeException('abandon failed');
        }
    }
}
