<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\PhpFileRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateDump;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpFileRouteTemplateProvider::class)]
final class OpcachedRouteTemplatesTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \uniqid('otel_routes_', true);
        \mkdir($dir);
        $this->dir = $dir;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $file = RouteTemplateDump::pathIn($this->dir);

        if (\is_file($file)) {
            \unlink($file);
        }

        \rmdir($this->dir);
    }

    #[Test]
    public function resolvesTemplateFromDump(): void
    {
        $this->dump(['app_orders_show' => '/orders/{id}']);

        self::assertSame('/orders/{id}', PhpFileRouteTemplateProvider::in($this->dir)->resolve('app_orders_show'));
    }

    #[Test]
    public function unknownRouteResolvesToNull(): void
    {
        $this->dump(['app_orders_show' => '/orders/{id}']);

        self::assertNull(PhpFileRouteTemplateProvider::in($this->dir)->resolve('app_missing'));
    }

    #[Test]
    public function missingDumpResolvesToNull(): void
    {
        self::assertNull(PhpFileRouteTemplateProvider::in($this->dir)->resolve('app_orders_show'));
    }

    /**
     * @param string $contents dump content that must not be usable
     */
    #[Test]
    #[DataProvider('unusableDumps')]
    public function unusableDumpResolvesToNull(string $contents): void
    {
        \file_put_contents(RouteTemplateDump::pathIn($this->dir), $contents);

        self::assertNull(PhpFileRouteTemplateProvider::in($this->dir)->resolve('app_orders_show'));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableDumps(): iterable
    {
        yield 'truncated file' => ["<?php\n\nreturn ['app_orders_show' =>"];
        yield 'not an array' => ["<?php\n\nreturn 'not an array';\n"];
        yield 'template not a string' => ["<?php\n\nreturn ['app_orders_show' => 42];\n"];
    }

    /**
     * Regression against require_once, which would return true on the second call.
     */
    #[Test]
    public function repeatedResolvesReturnTheSameTemplate(): void
    {
        $this->dump(['app_orders_show' => '/orders/{id}']);
        $templates = PhpFileRouteTemplateProvider::in($this->dir);

        self::assertSame('/orders/{id}', $templates->resolve('app_orders_show'));
        self::assertSame('/orders/{id}', $templates->resolve('app_orders_show'));
        self::assertSame('/orders/{id}', $templates->resolve('app_orders_show'));
    }

    /** @param array<string, string> $routeTemplates */
    private function dump(array $routeTemplates): void
    {
        \file_put_contents(RouteTemplateDump::pathIn($this->dir), RouteTemplateDump::render($routeTemplates));
    }
}
