<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;

/**
 * The boundaries each instrumented component measures with, unless the application
 * configured its own.
 *
 * Nine classes with two constant methods each said this before. They are data — one case
 * per component, and the reasoning for the ones that are not simply the HTTP set is on the
 * case itself. Being a backed enum is what lets a configuration key name a preset.
 *
 * Every set is in seconds: the duration conventions specify `s` for the operation-duration
 * instruments, and a histogram whose unit varies by component cannot be compared across
 * them.
 *
 * @internal
 */
enum DefaultBuckets: string implements OperationBuckets
{
    /**
     * The set the HTTP conventions recommend for `http.server.request.duration` and
     * `http.client.request.duration`.
     */
    case Http = 'http';

    /**
     * One statement, one round trip. The same shape serves the cache: both are a local or
     * near-local service answering in single-digit milliseconds when healthy, and the
     * question is how far into the tail the slow ones go.
     */
    case Database = 'database';

    case Cache = 'cache';

    /**
     * The boundaries the messaging conventions recommend for
     * `messaging.client.operation.duration` and `messaging.process.duration`.
     *
     * A separate set rather than a reuse: cache and serializer buckets stop at one second,
     * and a message handler routinely runs for minutes. Sending, on the other hand, is a
     * single write to a transport, so the low end has to stay fine enough to tell a local
     * in-memory transport from a network round trip.
     */
    case Messaging = 'messaging';

    /**
     * Serialization is CPU work on a structure already in memory, so the interesting
     * range starts two orders of magnitude below everything else here — a normalizer that
     * has become quadratic in the size of a graph shows up as a shift between buckets that
     * a millisecond-floor set would have collapsed into one.
     */
    case Serializer = 'serializer';

    /**
     * Boundaries for one transport invocation of the mailer.
     *
     * Not the HTTP set: the two ends of the distribution that matter here are not the ones
     * an HTTP call has. The bottom is a null or in-memory transport, which returns in
     * microseconds and would otherwise all land in a single first bucket together with a
     * fast API transport; the top is an SMTP conversation, which is several round trips plus
     * the body upload and routinely reaches tens of seconds before a transport's own timeout
     * cuts it off. Ten seconds — the top of the HTTP set — is where mail delivery starts
     * being interesting, not where it stops.
     */
    case Mail = 'mail';

    /**
     * Boundaries for one console command run.
     *
     * The widest set in the package, because a console command is the least constrained
     * thing the bundle measures: `cache:pool:prune` finishes in milliseconds and an import
     * command runs for a quarter of an hour, and both are the same instrument. Ten minutes
     * is the last boundary rather than an arbitrary larger one because a command that
     * exceeds it is a worker in everything but name and belongs in `excluded_commands` —
     * its span would stay open for the life of the process, which is exactly what the
     * exclusion list exists to prevent.
     */
    case Command = 'command';

    /**
     * Boundaries for one run of a scheduled task.
     *
     * A scheduled task is whatever the application put behind a cron expression, so the
     * useful range is wide: a cheap "is there anything to do" poll that returns in
     * milliseconds, and a nightly aggregation that runs for minutes, are both normal and
     * both have to stay distinguishable. The top boundary is five minutes because that is
     * roughly where a task stops being late and starts overlapping the next trigger of a
     * typical schedule — the point at which the distribution has said everything it can
     * and what is needed is an alert, not a finer bucket.
     */
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
