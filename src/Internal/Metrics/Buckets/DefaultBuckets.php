<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;

/**
 * Default histogram boundaries per instrumented component, in seconds as the duration
 * conventions require. A backed enum so configuration can name a preset.
 *
 * @internal
 */
enum DefaultBuckets: string implements OperationBuckets
{
    /** The HTTP conventions' recommended set. */
    case Http = 'http';

    /** One round trip to a nearby service; also used for the cache. */
    case Database = 'database';

    case Cache = 'cache';

    /** The messaging conventions' set: handlers can run for minutes. */
    case Messaging = 'messaging';

    /** In-memory CPU work, so the range starts well below a millisecond. */
    case Serializer = 'serializer';

    /** From an in-memory transport (microseconds) to an SMTP conversation (tens of seconds). */
    case Mail = 'mail';

    /** The widest set: a command past ten minutes is a worker and should be excluded. */
    case Command = 'command';

    /** From a quick poll to a nightly job; five minutes is where a task overlaps its next run. */
    case ScheduledTask = 'scheduled_task';

    #[\Override]
    public function unit(): DurationUnit
    {
        return DurationUnit::Seconds;
    }

    /**
     * @return non-empty-list<float|int>
     */
    #[\Override]
    public function boundaries(): array
    {
        return match ($this) {
            self::Http, self::Messaging => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10],
            self::Database, self::Cache => [0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10],
            self::Serializer => [0.0001, 0.000_25, 0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.5, 1],
            self::Mail => [0.001, 0.01, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60],
            self::Command => [0.05, 0.1, 0.5, 1, 2.5, 5, 10, 30, 60, 300, 600],
            self::ScheduledTask => [0.01, 0.05, 0.1, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300],
        };
    }
}
