<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the W3C trace context from the process that dispatched a message to the
 * worker that handles it.
 *
 * **This class name is part of the wire format.** The stamp is serialized into the
 * envelope next to the message — with the PHP serializer as the class name, with the
 * Symfony serializer as an `X-Message-Stamp-<FQCN>` header. Renaming or moving the
 * class breaks every message already sitting in a queue at deploy time, which is the
 * one kind of breakage a rolling deploy cannot undo. Treat it as frozen.
 *
 * The payload is whatever the configured propagator injected — normally `traceparent`
 * and, when present, `tracestate` and `baggage`. Keeping the carrier opaque is
 * deliberate: a bundle that changed its propagator would otherwise have to migrate
 * in-flight messages too.
 */
final readonly class TraceContextStamp implements StampInterface
{
    /**
     * @param array<string, string> $carrier
     */
    public function __construct(
        public array $carrier,
    ) {}
}
