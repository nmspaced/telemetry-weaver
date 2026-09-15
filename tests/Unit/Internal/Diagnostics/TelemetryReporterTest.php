<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Diagnostics;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(InstrumentationFailureReporter::class)]
final class TelemetryReporterTest extends TestCase
{
    /**
     * A systemic breakage fires on every request; an unlimited warning at
     * high rps becomes its own incident.
     */
    #[Test]
    public function theBurstIsFollowedByOneLinePerInterval(): void
    {
        $clock = new FrozenClock();
        $logger = new RecordingLogger();
        $reporter = new InstrumentationFailureReporter($logger, 2, 60.0, $clock);

        for ($i = 0; $i < 5; ++$i) {
            $reporter->report('detach failed', 'operation');
        }

        self::assertSame(2, $logger->count());

        $clock->advanceSeconds(61.0);
        $reporter->report('detach failed', 'operation');

        self::assertSame(3, $logger->count());
        self::assertSame(6, $reporter->total(), 'suppressed events still count');
        self::assertStringContainsString('6 total in this process', $logger->messageAt(2));
    }

    #[Test]
    public function theCauseIsNamedAndAttached(): void
    {
        $logger = new RecordingLogger();
        $cause = new \RuntimeException('storage is gone');

        new InstrumentationFailureReporter($logger)->report('detach failed', 'GET /orders', $cause);

        self::assertStringContainsString('detach failed at "GET /orders": storage is gone', $logger->messageAt(0));
        self::assertSame($cause, $logger->contextAt(0)['exception'] ?? null);
    }

    /**
     * The reporter is called from finally blocks that are already unwinding
     * an application exception. A broken logger must not replace it.
     */
    #[Test]
    public function aBrokenLoggerIsSwallowed(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @param array<array-key, mixed> $context
             *
             * @throws \RuntimeException always
             */
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): never
            {
                throw new \RuntimeException('logging is down');
            }
        };

        new InstrumentationFailureReporter($logger)->report('detach failed', 'operation');

        $this->expectNotToPerformAssertions();
    }
}
