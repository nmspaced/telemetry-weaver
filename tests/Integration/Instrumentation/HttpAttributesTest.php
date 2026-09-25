<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\QueryStringRedactor;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ServerSpanAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ServerSpanAttributes::class)]
#[CoversClass(QueryStringRedactor::class)]
final class HttpAttributesTest extends TestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theStandardAttributesAreDerivedFromTheRequest(): void
    {
        $request = Request::create('https://shop.example/orders/7', 'POST');
        $request->headers->set('User-Agent', 'probe/1.0');

        $attributes = new ServerSpanAttributes()->from($request);

        self::assertSame('POST', $attributes['http.request.method'] ?? null);
        self::assertSame('/orders/7', $attributes['url.path'] ?? null);
        self::assertSame('https', $attributes['url.scheme'] ?? null);
        self::assertSame('shop.example', $attributes['server.address'] ?? null);
        self::assertSame('probe/1.0', $attributes['user_agent.original'] ?? null);
        self::assertArrayNotHasKey('http.request.method_original', $attributes);
    }

    /** @throws \Throwable */
    #[Test]
    public function theClientAddressIsRecordedOnlyWhenAskedFor(): void
    {
        $request = Request::create('/orders', server: ['REMOTE_ADDR' => '203.0.113.7']);

        self::assertArrayNotHasKey('client.address', new ServerSpanAttributes()->from($request));
        self::assertSame(
            '203.0.113.7',
            new ServerSpanAttributes(recordClientIp: true)->from($request)['client.address'] ?? null,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function queries(): iterable
    {
        yield 'single' => ['token=secret', 'token=REDACTED'];
        yield 'repeated keys survive' => ['id=1&id=2', 'id=REDACTED&id=REDACTED'];
        yield 'nested names survive' => ['filter[status]=paid', 'filter[status]=REDACTED'];
        yield 'value containing = is fully redacted' => ['q=a=b&p=1', 'q=REDACTED&p=REDACTED'];
        yield 'valueless flag is kept' => ['debug&token=x', 'debug&token=REDACTED'];
        yield 'a literal semicolon does not end a value' => [
            'token=abc;private-secret',
            'token=REDACTED',
        ];
        yield 'a semicolon between pairs does not end a value' => ['a=1;b=2', 'a=REDACTED'];
        yield 'an encoded semicolon does not end a value' => ['token=abc%3Bsecret', 'token=REDACTED'];
        yield 'an empty value stays empty' => ['token=&page=2', 'token=REDACTED&page=REDACTED'];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('queries')]
    public function noParsedValueSurvivesRedaction(string $query, string $_expected): void
    {
        $parsed = [];
        \parse_str($query, $parsed);
        $redacted = QueryStringRedactor::redact($query);

        \array_walk_recursive($parsed, static function (mixed $value) use ($redacted): void {
            if (!\is_string($value) || $value === '') {
                return;
            }

            self::assertStringNotContainsString($value, $redacted);
        });
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('queries')]
    public function everyQueryValueIsRedacted(string $query, string $expected): void
    {
        self::assertSame($expected, QueryStringRedactor::redact($query));
    }

    /** @throws \Throwable */
    #[Test]
    public function theQueryReachesTheAttributesRedacted(): void
    {
        $request = Request::create('/orders?token=secret&page=2');

        self::assertSame(
            'token=REDACTED&page=REDACTED',
            new ServerSpanAttributes()->from($request)['url.query'] ?? null,
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function theBaseUrlIsPartOfThePath(): void
    {
        $request = Request::create('/app.php/orders/7');
        $request->server->set('SCRIPT_FILENAME', '/var/www/public/app.php');
        $request->server->set('SCRIPT_NAME', '/app.php');
        $request->server->set('PHP_SELF', '/app.php');

        self::assertSame('/app.php/orders/7', new ServerSpanAttributes()->from($request)['url.path'] ?? null);
    }
}
