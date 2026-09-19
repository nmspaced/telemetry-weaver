<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OwnedSpan;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ShutdownScopeCleanup;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerSpanAssertions;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The invariant the whole package exists for, at the scale where a leak becomes visible.
 *
 * Two messages prove that the happy path unwinds; a long run proves that nothing
 * accumulates while it does. Both properties are about to be rewritten — consumption
 * moves off `Context::getRoot()` and onto a propagation boundary — and a per-message
 * activation that stopped being released would still pass every existing test, because
 * none of them counts what is left behind.
 *
 * What is counted is the set of owners that are still activated, not memory: the in-memory
 * exporter retains every span on purpose, so the process must grow, and a byte-level
 * assertion here would measure the harness rather than the subject.
 */
#[CoversClass(MessengerConsumption::class)]
final class MessengerWorkerIsolationTest extends MessengerTelemetryTestCase
{
    private const int MESSAGES = 1_000;

    /** @throws \Throwable|\ReflectionException */
    #[Test]
    public function aLongRunGivesEveryMessageItsOwnTraceAndLeavesNoActivationBehind(): void
    {
        $activationsBefore = self::activatedOwners();

        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher);

        for ($i = 0; $i < self::MESSAGES; ++$i) {
            $bus->dispatch(new SampleMessage('message-' . $i));
        }

        $this->work($telemetry, $dispatcher, $bus);

        $processed = MessengerSpanAssertions::spansNamed($this->spans, 'process async');
        self::assertCount(self::MESSAGES, $processed);

        $traces = \array_unique(\array_map(
            static fn(ImmutableSpan $span): string => $span->getContext()->getTraceId(),
            $processed,
        ));
        self::assertCount(self::MESSAGES, $traces, "a message inherited another message's trace");

        self::assertNull(Context::storage()->scope(), 'the worker left a scope on the stack');
        self::assertSame(
            $activationsBefore,
            self::activatedOwners(),
            'an operation stayed activated after its message was processed',
        );
        self::assertSame([], $this->logger->messages());
    }

    /**
     * How many spans this process still holds activated.
     *
     * The registry is private static and weakly keyed, which is exactly why it answers the
     * question: an owner that released its scope calls `forget()` and leaves immediately,
     * so a non-zero delta is a scope nobody detached rather than an object awaiting GC.
     *
     * @throws \ReflectionException
     */
    private static function activatedOwners(): int
    {
        $owners = new \ReflectionProperty(ShutdownScopeCleanup::class, 'owners');

        /** @var \WeakMap<OwnedSpan, null>|null $map */
        $map = $owners->getValue();

        return $map === null ? 0 : \count($map);
    }
}
