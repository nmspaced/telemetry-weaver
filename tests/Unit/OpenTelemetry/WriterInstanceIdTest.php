<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\WriterInstanceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WriterInstanceId::class)]
final class WriterInstanceIdTest extends TestCase
{
    #[Test]
    public function oneChildYieldsTheSameVersion5UuidOnEveryRequest(): void
    {
        $child = ['host.name' => 'web-1', 'process.pid' => 4242];

        $first = WriterInstanceId::derive($child);
        $second = WriterInstanceId::derive($child);

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first,
        );
    }

    #[Test]
    public function anotherChildHostOrContainerIsAnotherWriter(): void
    {
        $ids = [
            WriterInstanceId::derive(['host.name' => 'web-1', 'process.pid' => 1]),
            WriterInstanceId::derive(['host.name' => 'web-1', 'process.pid' => 2]),
            WriterInstanceId::derive(['host.name' => 'web-2', 'process.pid' => 1]),
            WriterInstanceId::derive(['host.id' => 'm', 'container.id' => 'a', 'process.pid' => 7]),
            WriterInstanceId::derive(['host.id' => 'm', 'container.id' => 'b', 'process.pid' => 7]),
        ];

        self::assertNotContains(null, $ids);
        self::assertCount(\count($ids), \array_unique($ids));
    }

    #[Test]
    public function withoutAPidOrAPlaceThereIsNothingToDerive(): void
    {
        self::assertNull(WriterInstanceId::derive(['host.name' => 'web-1']));
        self::assertNull(WriterInstanceId::derive(['process.pid' => 1]));
        self::assertNull(WriterInstanceId::derive(['host.name' => '', 'process.pid' => 1]));
    }
}
