<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\CacheOperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\CommandOperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\CustomOperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DatabaseOperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\HttpOperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\MailOperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\MessagingOperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\ScheduledTaskBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\SerializerOperationBuckets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpOperationBuckets::class)]
#[CoversClass(DatabaseOperationBuckets::class)]
#[CoversClass(CacheOperationBuckets::class)]
#[CoversClass(MessagingOperationBuckets::class)]
#[CoversClass(SerializerOperationBuckets::class)]
#[CoversClass(MailOperationBuckets::class)]
#[CoversClass(ScheduledTaskBuckets::class)]
#[CoversClass(CommandOperationBuckets::class)]
#[CoversClass(CustomOperationBuckets::class)]
#[CoversClass(DurationUnit::class)]
final class TimerBucketsTest extends TestCase
{
    /** @return iterable<string, array{OperationBuckets}> */
    public static function buckets(): iterable
    {
        yield 'http' => [new HttpOperationBuckets()];
        yield 'database' => [new DatabaseOperationBuckets()];
        yield 'cache' => [new CacheOperationBuckets()];
        yield 'messaging' => [new MessagingOperationBuckets()];
        yield 'serializer' => [new SerializerOperationBuckets()];
        yield 'mail' => [new MailOperationBuckets()];
        yield 'scheduled task' => [new ScheduledTaskBuckets()];
        yield 'console command' => [new CommandOperationBuckets()];
    }

    /**
     * The SDK doesn't reject unordered boundaries — it builds buckets from them as-is, and the histogram silently becomes meaningless.
     */
    #[Test]
    #[DataProvider('buckets')]
    public function boundariesAreStrictlyIncreasing(OperationBuckets $buckets): void
    {
        $boundaries = $buckets->boundaries();
        $sorted = $boundaries;
        \sort($sorted);

        self::assertNotEmpty($boundaries);
        self::assertSame($sorted, $boundaries, 'boundaries must be increasing');
        self::assertSame(\array_values(\array_unique($boundaries)), $boundaries, 'no duplicates allowed');
    }

    #[Test]
    #[DataProvider('buckets')]
    public function boundariesArePositive(OperationBuckets $buckets): void
    {
        self::assertGreaterThan(0, $buckets->boundaries()[0]);
    }

    #[Test]
    public function unitsMatchTheScaleOfTheOperation(): void
    {
        self::assertSame(DurationUnit::Seconds, new HttpOperationBuckets()->unit());
        self::assertSame(DurationUnit::Seconds, new DatabaseOperationBuckets()->unit());
        self::assertSame(DurationUnit::Seconds, new CacheOperationBuckets()->unit());
        self::assertSame(DurationUnit::Seconds, new MessagingOperationBuckets()->unit());
        self::assertSame(DurationUnit::Seconds, new SerializerOperationBuckets()->unit());
    }

    #[Test]
    public function nanosecondsConvertToTheDeclaredUnit(): void
    {
        self::assertSame(1.5, DurationUnit::Milliseconds->fromNanoseconds(1_500_000));
        self::assertSame(1_500.0, DurationUnit::Microseconds->fromNanoseconds(1_500_000));
        self::assertSame(0.0015, DurationUnit::Seconds->fromNanoseconds(1_500_000));
    }

    #[Test]
    public function customBucketsRejectUnorderedBoundaries(): void
    {
        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessageMatches('/strictly increasing/');

        new CustomOperationBuckets(DurationUnit::Milliseconds, [1, 10, 5]);
    }

    /**
     * An empty boundary set is rejected in the constructor; the non-empty-list annotation is a caller promise, not a runtime check.
     */
    #[Test]
    public function customBucketsRejectEmptyBoundaries(): void
    {
        self::expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore-next-line deliberate contract violation */
        new CustomOperationBuckets(DurationUnit::Milliseconds, []);
    }

    #[Test]
    public function customBucketsKeepWhatTheyWereGiven(): void
    {
        $buckets = new CustomOperationBuckets(DurationUnit::Seconds, [0.5, 1, 2.5]);

        self::assertSame(DurationUnit::Seconds, $buckets->unit());
        self::assertSame([0.5, 1, 2.5], $buckets->boundaries());
    }
}
