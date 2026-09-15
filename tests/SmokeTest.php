<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SmokeTest extends TestCase
{
    #[Test]
    public function openTelemetryApiIsAvailable(): void
    {
        self::assertNotNull(Globals::tracerProvider()->getTracer('test'));
    }
}
