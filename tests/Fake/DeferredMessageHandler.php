<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation\SampleMessage;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;

/** Defers all acknowledgements until the worker flushes, even across deliveries. */
final class DeferredMessageHandler implements BatchHandlerInterface
{
    /** @var list<array{SampleMessage, Acknowledger}> */
    private array $pending = [];

    /** @var list<string> */
    public array $processed = [];

    public function __invoke(SampleMessage $message, Acknowledger $ack): int
    {
        $this->pending[] = [$message, $ack];

        return \count($this->pending);
    }

    #[\Override]
    public function flush(bool $force): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$message, $ack]) {
            $this->processed[] = $message->name;
            $ack->ack($message->name);
        }
    }
}
