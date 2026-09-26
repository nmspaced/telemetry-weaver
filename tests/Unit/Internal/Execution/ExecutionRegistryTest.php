<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Execution;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionRegistry;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingEntry;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExecutionRegistry::class)]
final class ExecutionRegistryTest extends TestCase
{
    #[Test]
    public function resetAbandonsInnermostFirstAndOneFailureDoesNotStopTheRest(): void
    {
        $logger = new RecordingLogger();
        $reporter = new InstrumentationFailureReporter($logger);
        /** @var ExecutionRegistry<RecordingEntry> $registry */
        $registry = new ExecutionRegistry($reporter, 'test entries');
        /** @var \ArrayObject<int, string> $abandoned */
        $abandoned = new \ArrayObject();
        $outerKey = new \stdClass();
        $innerKey = new \stdClass();
        $outer = $registry->open($outerKey, static fn(): RecordingEntry => new RecordingEntry('outer', $abandoned));
        $inner = $registry->open(
            $innerKey,
            static fn(): RecordingEntry => new RecordingEntry('inner', $abandoned, true),
        );

        $registry->reset();

        self::assertSame(['inner', 'outer'], $abandoned->getArrayCopy());
        self::assertNull($registry->of($outerKey));
        self::assertNull($registry->of($innerKey));
        self::assertSame(1, $reporter->total());
        self::assertStringContainsString('abandoning an unfinished execution failed', $logger->messageAt(0));
        self::assertNotSame($outer, $inner);
    }

    #[Test]
    public function aSecondOpenForTheSameKeyReusesTheEntry(): void
    {
        $registry = new ExecutionRegistry(new InstrumentationFailureReporter(new RecordingLogger()), 'test entries');
        $key = new \stdClass();
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();

        $first = $registry->open($key, static fn(): RecordingEntry => new RecordingEntry('first', $log));
        $second = $registry->open($key, static fn(): RecordingEntry => new RecordingEntry('second', $log));

        self::assertSame($first, $second);
    }
}
