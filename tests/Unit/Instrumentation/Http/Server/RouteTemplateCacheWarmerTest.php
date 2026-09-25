<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http\Server;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\PhpFileRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateCacheWarmer;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateDump;
use Nmspaced\TelemetryWeaver\Tests\Fake\StubRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

/**
 * The dump this writes is what every server span's `http.route` is read from in production — the
 * router itself is never touched on the request path.
 */
#[CoversClass(RouteTemplateCacheWarmer::class)]
#[CoversClass(RouteTemplateDump::class)]
final class RouteTemplateCacheWarmerTest extends TestCase
{
    private string $dir;

    /** @throws RandomException */
    #[\Override]
    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/weaver-warmer-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = \glob($this->dir . '/*');

        foreach ($files === false ? [] : $files as $file) {
            \unlink($file);
        }

        \rmdir($this->dir);
    }

    #[Test]
    public function theWarmerIsNotOptional(): void
    {
        self::assertFalse(new RouteTemplateCacheWarmer()->isOptional());
    }

    #[Test]
    public function theDumpIsReadableByTheProviderThatConsumesIt(): void
    {
        $this->warm(['order_show' => '/orders/{id}', 'health' => '/health']);

        $provider = PhpFileRouteTemplateProvider::in($this->dir);

        self::assertSame('/orders/{id}', $provider->resolve('order_show'));
        self::assertSame('/health', $provider->resolve('health'));
        self::assertNull($provider->resolve('absent'));
    }

    #[Test]
    public function theBuildDirectoryWinsOverTheCacheDirectory(): void
    {
        $cacheDir = $this->dir . '/cache';
        \mkdir($cacheDir);

        new RouteTemplateCacheWarmer(new StubRouter(['a' => '/a']))->warmUp($cacheDir, $this->dir);

        self::assertFileExists(RouteTemplateDump::pathIn($this->dir));
        self::assertFileDoesNotExist(RouteTemplateDump::pathIn($cacheDir));
        \rmdir($cacheDir);
    }

    #[Test]
    public function theDumpIsOrderedByRouteName(): void
    {
        $this->warm(['zeta' => '/z', 'alpha' => '/a', 'mid' => '/m']);

        /** @var array<string, string> $dump */
        $dump = include RouteTemplateDump::pathIn($this->dir);

        self::assertSame(['alpha', 'mid', 'zeta'], \array_keys($dump));
    }

    #[Test]
    public function anApplicationWithNoRoutesStillGetsAReadableDump(): void
    {
        $this->warm([]);

        self::assertSame([], include RouteTemplateDump::pathIn($this->dir));
        self::assertNull(PhpFileRouteTemplateProvider::in($this->dir)->resolve('anything'));
    }

    #[Test]
    public function withoutARouterNothingIsWritten(): void
    {
        self::assertSame([], new RouteTemplateCacheWarmer()->warmUp($this->dir));
        self::assertFileDoesNotExist(RouteTemplateDump::pathIn($this->dir));
    }

    #[Test]
    public function aThrowingRouterDoesNotBreakTheWarmup(): void
    {
        $router = StubRouter::failing(new \RuntimeException('no routes'));

        self::assertSame([], new RouteTemplateCacheWarmer($router)->warmUp($this->dir));
        self::assertFileDoesNotExist(RouteTemplateDump::pathIn($this->dir));
    }

    /** @param array<string, string> $routes */
    private function warm(array $routes): void
    {
        new RouteTemplateCacheWarmer(new StubRouter($routes))->warmUp($this->dir);
    }
}
