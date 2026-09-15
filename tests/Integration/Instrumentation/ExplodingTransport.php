<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** @internal a transport that refuses every send, to exercise the failed-attempt path */
final class ExplodingTransport extends InMemoryTransport
{
    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function send(Envelope $envelope): Envelope
    {
        throw new \RuntimeException('the broker is not there');
    }
}
