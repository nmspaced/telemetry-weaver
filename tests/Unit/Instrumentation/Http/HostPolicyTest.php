<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HostPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HostPolicyTest extends TestCase
{
    #[Test]
    public function aWildcardExclusionCoversSubdomainsButNotTheApex(): void
    {
        $policy = new HostPolicy(['*.example.org', 'other.test.']);

        self::assertFalse($policy->trace('api.example.org'));
        self::assertFalse($policy->trace('DEEP.api.Example.org.'));
        self::assertTrue($policy->trace('example.org'));
        self::assertTrue($policy->trace('notexample.org'));
        self::assertFalse($policy->trace('OTHER.test'));
        self::assertTrue($policy->measure('api.example.org'));
    }
}
