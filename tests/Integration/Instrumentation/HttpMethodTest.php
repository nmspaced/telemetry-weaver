<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\KnownHttpMethods;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ServerSpanAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(HttpMethod::class)]
#[CoversClass(KnownHttpMethods::class)]
final class HttpMethodTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        unset($_SERVER['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS']);
    }

    /** @return iterable<string, array{string, string, ?string, string}> */
    public static function methods(): iterable
    {
        yield 'known' => ['GET', 'GET', null, 'GET'];
        yield 'lowercase is normalized and preserved' => ['get', 'GET', 'get', 'GET'];
        yield 'unknown becomes _OTHER' => ['FROBNICATE', '_OTHER', 'FROBNICATE', 'HTTP'];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('methods')]
    public function theMethodIsNormalizedWithItsOriginalPreserved(
        string $wire,
        string $value,
        ?string $original,
        string $spanName,
    ): void {
        $request = Request::create('/orders');
        $request->server->set('REQUEST_METHOD', $wire);

        $method = HttpMethod::from($request);

        self::assertSame($value, $method->value);
        self::assertSame($spanName, $method->spanName());
        self::assertSame($original, new ServerSpanAttributes()->from($request)['http.request.method_original'] ?? null);
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnreadableMethodDoesNotEmitAnEmptyOriginal(): void
    {
        $request = Request::create('/orders');
        $request->server->set('REQUEST_METHOD', 123);

        $attributes = new ServerSpanAttributes()->from($request);

        self::assertSame('_OTHER', $attributes['http.request.method'] ?? null);
        self::assertArrayNotHasKey('http.request.method_original', $attributes);
    }

    /** @return iterable<string, array{string}> */
    public static function blankSettings(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'separators only' => [' , , '];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('blankSettings')]
    public function aBlankKnownMethodsSettingFallsBackToTheDefaults(string $configured): void
    {
        $_SERVER['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS'] = $configured;

        self::assertTrue(new KnownHttpMethods()->contains('GET'));
    }

    /** @throws \Throwable */
    #[Test]
    public function aConfiguredListReplacesTheDefaults(): void
    {
        $_SERVER['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS'] = 'GET, PURGE';
        $known = new KnownHttpMethods();

        self::assertTrue($known->contains('PURGE'));
        self::assertTrue($known->contains('GET'));
        self::assertFalse($known->contains('POST'));
    }
}
