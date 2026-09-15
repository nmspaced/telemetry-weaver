<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;

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
final readonly class MailOperationBuckets implements OperationBuckets
{
    #[\Override]
    public function unit(): DurationUnit
    {
        return DurationUnit::Seconds;
    }

    /** @return non-empty-list<float|int> */
    #[\Override]
    public function boundaries(): array
    {
        return [
            0.001,
            0.01,
            0.05,
            0.1,
            0.25,
            0.5,
            1,
            2.5,
            5,
            10,
            30,
            60,
        ];
    }
}
