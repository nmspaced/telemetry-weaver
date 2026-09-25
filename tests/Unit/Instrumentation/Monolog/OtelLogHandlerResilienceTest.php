<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Monolog;

use Monolog\Logger;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\ChannelLoggers;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\OtelLogHandler;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Fake\ThrowingLoggerProvider;
use Nmspaced\TelemetryWeaver\Tests\Support\OtelLogHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The handler must survive a failing export without re-entering itself or raising at the call
 * site, and its per-channel logger cache must stay bounded under worker-mode load.
 */
#[CoversClass(OtelLogHandler::class)]
#[CoversClass(ChannelLoggers::class)]
final class OtelLogHandlerResilienceTest extends OtelLogHandlerTestCase
{
    #[Test]
    public function aLogEmittedWhileExportingDoesNotReEnterTheHandler(): void
    {
        $logger = null;
        $provider = new ThrowingLoggerProvider(static function () use (&$logger): void {
            $logger?->error('the export failed');
        });

        $logger = new Logger('app', [
            new OtelLogHandler($provider, new InstrumentationFailureReporter(new RecordingLogger())),
        ]);
        $logger->error('original');

        self::assertSame(1, $provider->attempts, 'the nested record must not start a second export');
    }

    #[Test]
    public function aFailingExportIsContainedRatherThanRaisedAtTheCallSite(): void
    {
        $reported = new RecordingLogger();
        $logger = new Logger('app', [
            new OtelLogHandler(new ThrowingLoggerProvider(), new InstrumentationFailureReporter($reported)),
        ]);

        $logger->error('the application still expects this call to return');

        self::assertNotSame([], $reported->records);
    }

    /** @throws \ReflectionException */
    #[Test]
    public function theLoggerCacheIsBoundedButEveryChannelStillExports(): void
    {
        $handler = $this->handler();
        $held = [];
        $logger = new Logger('base', [$handler]);

        for ($i = 0; $i < 300; ++$i) {
            $logger->withName(\sprintf('channel-%d', $i))->info('probe');
        }

        /** @var \WeakMap<object, mixed> $loggers */
        $loggers = new \ReflectionProperty($this->provider, 'loggers')->getValue($this->provider);
        foreach ($loggers as $sdkLogger => $_) {
            $held[] = $sdkLogger;
        }

        self::assertLessThanOrEqual(ChannelLoggers::MAX, \count($held), 'loggers still alive');
        self::assertCount(300, $this->records());
        self::assertSame('channel-299', $this->record(299)->getInstrumentationScope()->getName());
    }
}
