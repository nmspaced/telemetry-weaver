<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

final class ShutdownScopeCleanupTest extends TelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function exitInsideAConsumerUnwindsNestedScopesWithoutExporting(): void
    {
        $process = new Process([
            \PHP_BINARY,
            '-d',
            'zend.assertions=1',
            '-d',
            'display_errors=stderr',
            \dirname(__DIR__, 3) . '/Fixtures/messenger-shutdown.php',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
    }

    #[Test]
    public function completedOwnersAreNotRetainedByTheShutdownCallback(): void
    {
        $owner = $this->spans->open('completed', new SpanOptions());
        $weak = \WeakReference::create($owner);
        $owner->finish();
        unset($owner);

        self::assertNull($weak->get());
        self::assertNull($this->contextStorage->scope());
        $this->assertNoReports();
    }

    #[Test]
    public function losingAnOwnerDetachesWithoutExportingAnUnfinishedOperation(): void
    {
        $owner = $this->spans->open('abandoned', new SpanOptions());
        $weak = \WeakReference::create($owner);
        unset($owner);

        self::assertNull($weak->get());
        self::assertNull($this->contextStorage->scope());
        self::assertSame([], $this->exported());
        $this->assertNoReports();
    }
}
