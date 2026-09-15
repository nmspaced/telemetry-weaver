<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** PHP shutdown cannot be triggered inside PHPUnit's own process, so each case is a fresh one. */
#[CoversClass(ExportGate::class)]
final class ProcessExitTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function exits(): iterable
    {
        yield 'a worker whose loop returned delivers what the last boundary left queued' => ['worker', 1];
        yield 'a request pipeline that missed terminate is discarded without export' => ['request', 0];
        yield 'a worker dying of a fatal error does not run exporter code' => ['fatal', 0];
        yield 'a pipeline whose flusher was released is not kept alive for the exit' => ['released', 0];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('exits')]
    public function processExitFinishesOnlyPipelinesThatOutliveRequests(string $mode, int $exported): void
    {
        $process = new Process([
            \PHP_BINARY,
            '-d',
            'zend.assertions=1',
            '-d',
            'display_errors=stderr',
            \dirname(__DIR__, 3) . '/Fixtures/worker-exit.php',
            $mode,
        ]);
        $process->run();

        self::assertSame($mode === 'fatal' ? 255 : 0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            ['exported' => $exported, 'closed' => true, 'failures' => 0],
            \json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
