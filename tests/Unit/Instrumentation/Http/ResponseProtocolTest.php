<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\ResponseProtocol;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseProtocol::class)]
final class ResponseProtocolTest extends TestCase
{
    /** @return iterable<string, array{mixed, string|null}> */
    public static function headers(): iterable
    {
        yield 'http/1.1' => [['HTTP/1.1 200 OK', 'content-type: text/plain'], '1.1'];
        yield 'http/2 without a reason' => [['HTTP/2 204'], '2'];
        yield 'http/2.0 is http/2' => [['HTTP/2.0 200 OK'], '2'];
        yield 'http/3' => [['HTTP/3 200'], '3'];
        yield 'http/1.0 keeps its minor' => [['HTTP/1.0 200 OK'], '1.0'];
        yield 'the final response after a redirect' => [['HTTP/1.1 301 Moved', 'location: /x', 'HTTP/2 200'], '2'];
        yield 'after an interim response' => [['HTTP/1.1 103 Early Hints', 'HTTP/1.1 200 OK'], '1.1'];
        yield 'no status line' => [['content-type: text/plain'], null];
        yield 'a header that only looks like one' => [['x-note: HTTP/2 200'], null];
        yield 'not a list' => ['HTTP/2 200', null];
        yield 'nothing' => [null, null];
    }

    #[Test]
    #[DataProvider('headers')]
    public function theVersionComesFromTheLastStatusLine(mixed $headers, ?string $version): void
    {
        self::assertSame($version, ResponseProtocol::of($headers));
    }
}
