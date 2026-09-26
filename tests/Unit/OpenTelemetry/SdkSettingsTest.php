<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\FlushPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpTransportSettings;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Settings from the environment or the bundle config that the SDK would not accept as they are. */
#[CoversClass(FlushPolicy::class)]
#[CoversClass(OtlpTransportSettings::class)]
final class SdkSettingsTest extends TestCase
{
    private mixed $previousDelay = null;

    #[\Override]
    protected function setUp(): void
    {
        FlushPolicy::resetProcessState();
        $this->previousDelay = $_SERVER[Variables::OTEL_BSP_SCHEDULE_DELAY] ?? null;
    }

    #[\Override]
    protected function tearDown(): void
    {
        FlushPolicy::resetProcessState();

        if ($this->previousDelay === null) {
            unset($_SERVER[Variables::OTEL_BSP_SCHEDULE_DELAY]);

            return;
        }

        $_SERVER[Variables::OTEL_BSP_SCHEDULE_DELAY] = $this->previousDelay;
    }

    /** @return iterable<string, array{string}> */
    public static function unusableDelays(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'not a number' => ['soon'];
    }

    #[Test]
    #[DataProvider('unusableDelays')]
    public function anUnusableScheduleDelayFallsBackToTheSdkDefault(string $delay): void
    {
        $_SERVER[Variables::OTEL_BSP_SCHEDULE_DELAY] = $delay;
        $clock = new FrozenClock();
        $policy = FlushPolicy::onSdkSchedule('traces', $clock);

        self::assertTrue($policy->shouldFlush());
        $clock->advanceNanoseconds((Defaults::OTEL_BSP_SCHEDULE_DELAY - 1) * 1_000_000);
        self::assertFalse($policy->shouldFlush(), 'not before the default delay');
        $clock->advanceNanoseconds(1_000_000);
        self::assertTrue($policy->shouldFlush());
    }

    #[Test]
    public function configuredHeadersAreSentAsTextAndNullRemovesOne(): void
    {
        $settings = new OtlpTransportSettings(headers: [
            'x-debug' => true,
            'x-shard' => 3,
            'authorization' => null,
        ]);

        self::assertSame(
            ['x-tenant' => 'first', 'x-debug' => 'true', 'x-shard' => '3'],
            $settings->headers(['x-tenant' => 'first', 'authorization' => 'Bearer secret']),
        );
    }
}
