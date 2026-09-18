<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\CustomOperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultBuckets::class)]
#[CoversClass(CustomOperationBuckets::class)]
#[CoversClass(DurationUnit::class)]
final class TimerBucketsTest extends TestCase
{
    /**
     * Every case, found by reflection rather than listed: a preset added without a test is
     * the way an unordered boundary set would get in.
     *
     * @return iterable<string, array{DefaultBuckets}>
     */
    public static function buckets(): iterable
    {
        foreach (DefaultBuckets::cases() as $case) {
            yield $case->value => [$case];
        }
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

    /**
     * The duration conventions specify `s` for the operation-duration instruments, and a
     * histogram whose unit varies by component cannot be compared across them.
     */
    #[Test]
    #[DataProvider('buckets')]
    public function everyPresetMeasuresInSeconds(DefaultBuckets $buckets): void
    {
        self::assertSame(DurationUnit::Seconds, $buckets->unit());
    }

    /**
     * The presets the component defaults name, and the shape each promises. A set that
     * quietly changed range would change every dashboard built on it.
     */
    #[Test]
    public function theDocumentedPresetsKeepTheirRange(): void
    {
        self::assertSame([0.005, 10], self::range(DefaultBuckets::Http));
        self::assertSame([0.0001, 1], self::range(DefaultBuckets::Serializer));
        self::assertSame([0.001, 10], self::range(DefaultBuckets::Database));
        self::assertSame([0.001, 60], self::range(DefaultBuckets::Mail));
        self::assertSame([0.05, 600], self::range(DefaultBuckets::Command));
        self::assertSame([0.01, 300], self::range(DefaultBuckets::ScheduledTask));
    }

    /** @return array{float|int, float|int} the first and last boundary */
    private static function range(DefaultBuckets $buckets): array
    {
        $boundaries = $buckets->boundaries();
        $last = \array_slice($boundaries, -1);

        return [$boundaries[0], $last[0] ?? Assert::fail('a preset with no boundaries')];
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
