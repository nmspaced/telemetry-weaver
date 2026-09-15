<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateDump;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteTemplateDump::class)]
final class RouteTemplateTest extends TestCase
{
    /**
     * Only a flat literal array<string, string> is kept immutable in SHM by opcache.
     */
    #[Test]
    public function dumpIsAFlatArrayLiteral(): void
    {
        $rendered = RouteTemplateDump::render(['app_home' => '/', 'app_orders_show' => '/orders/{id}']);

        self::assertStringStartsWith("<?php\n\nreturn array (", $rendered);
        self::assertStringNotContainsString('::', $rendered);
        self::assertStringNotContainsString('function', $rendered);
        self::assertStringNotContainsString('\\stdClass', $rendered);
    }

    #[Test]
    public function pathIsBuiltInsideTheGivenDirectory(): void
    {
        self::assertSame(
            '/tmp/build' . \DIRECTORY_SEPARATOR . RouteTemplateDump::FILE_NAME,
            RouteTemplateDump::pathIn('/tmp/build/'),
        );
    }

    #[Test]
    public function freshnessIsFalseWhenTheDumpIsMissing(): void
    {
        self::assertFalse(RouteTemplateDump::isFreshIn(\sys_get_temp_dir() . '/nmspaced-otel-missing'));
    }
}
