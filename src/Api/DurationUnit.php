<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

enum DurationUnit: string
{
    case Nanoseconds = 'ns';

    case Microseconds = 'us';

    case Milliseconds = 'ms';

    case Seconds = 's';

    public function divisorFromNanoseconds(): int
    {
        return match ($this) {
            self::Nanoseconds => 1,
            self::Microseconds => 1_000,
            self::Milliseconds => 1_000_000,
            self::Seconds => 1_000_000_000,
        };
    }

    public function fromNanoseconds(int $nanoseconds): float
    {
        return $nanoseconds / $this->divisorFromNanoseconds();
    }
}
