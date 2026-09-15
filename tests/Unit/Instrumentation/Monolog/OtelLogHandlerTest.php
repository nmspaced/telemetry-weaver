<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Monolog;

use Monolog\Logger;
use Monolog\LogRecord;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\OtelLogHandler;
use Nmspaced\TelemetryWeaver\Tests\Support\OtelLogHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mapping a Monolog record into a `ReadableLogRecord`: severity, body, flattened context, the
 * exception carve-out, and the level/channel filters. Re-entrancy and cache-boundedness live in
 * {@see OtelLogHandlerResilienceTest}.
 */
#[CoversClass(OtelLogHandler::class)]
final class OtelLogHandlerTest extends OtelLogHandlerTestCase
{
    #[Test]
    public function aRecordBecomesALogRecordWithItsSeverityBodyAndFlattenedContext(): void
    {
        $logger = new Logger('app', [$this->handler()]);
        $logger->warning('order {id} failed', ['id' => 42, 'tags' => ['a', 'b']]);

        self::assertCount(1, $this->records());

        $record = $this->record();
        self::assertSame('order {id} failed', $record->getBody());
        self::assertSame('WARNING', $record->getSeverityText());
        self::assertSame('app', $record->getInstrumentationScope()->getName());
        self::assertSame(42, $record->getAttributes()->get('context.id'));
        self::assertSame('["a","b"]', $record->getAttributes()->get('context.tags'));
    }

    #[Test]
    public function anExceptionInTheContextBecomesTheRecordsExceptionRatherThanAnAttribute(): void
    {
        $logger = new Logger('app', [$this->handler()]);
        $logger->error('boom', ['exception' => new \RuntimeException('detonated')]);

        $attributes = $this->record()->getAttributes();

        self::assertNull($attributes->get('context.exception'));
        self::assertSame(\RuntimeException::class, $attributes->get('exception.type'));
        self::assertSame('detonated', $attributes->get('exception.message'));
    }

    #[Test]
    public function theCorrelationKeysAnotherHandlerNeedsAreNotCopiedIntoAttributes(): void
    {
        $logger = new Logger('app', [$this->handler()]);
        $logger->pushProcessor(static function (LogRecord $record): LogRecord {
            $record->extra['trace_id'] = 'deadbeef';
            $record->extra['request_id'] = 'r-1';

            return $record;
        });
        $logger->error('boom');

        $attributes = $this->record()->getAttributes();

        self::assertNull($attributes->get('extra.trace_id'));
        self::assertSame('r-1', $attributes->get('extra.request_id'));
    }

    #[Test]
    public function anExcludedChannelIsNeverExported(): void
    {
        $handler = $this->handler(excludedChannels: ['noisy']);

        new Logger('noisy', [$handler])->error('ignored');
        new Logger('app', [$handler])->error('kept');

        self::assertCount(1, $this->records());
        self::assertSame('kept', $this->record()->getBody());
    }

    #[Test]
    public function recordsBelowTheConfiguredLevelDoNotReachTheExporter(): void
    {
        $logger = new Logger('app', [$this->handler(level: 'error')]);
        $logger->warning('below');
        $logger->error('at level');

        self::assertCount(1, $this->records());
        self::assertSame('at level', $this->record()->getBody());
    }
}
