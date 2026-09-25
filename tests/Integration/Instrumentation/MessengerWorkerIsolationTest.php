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

/** A long-running worker gives every message its own trace and leaves no context behind. */
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
