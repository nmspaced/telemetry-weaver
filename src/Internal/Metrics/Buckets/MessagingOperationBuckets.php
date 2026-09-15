<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;

/**
 * The boundaries the messaging conventions recommend for
 * `messaging.client.operation.duration` and `messaging.process.duration`.
 *
 * A separate set rather than a reuse: cache and serializer buckets stop at one second,
 * and a message handler routinely runs for minutes. Sending, on the other hand, is a
 * single write to a transport, so the low end has to stay fine enough to tell a local
 * in-memory transport from a network round trip.
 */
final readonly class MessagingOperationBuckets implements OperationBuckets
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
            0.005,
            0.01,
            0.025,
            0.05,
            0.075,
            0.1,
            0.25,
            0.5,
            0.75,
            1,
            2.5,
            5,
            7.5,
            10,
        ];
    }
}
