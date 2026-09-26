<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ScopeConfinement;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/** The guards that keep releasing a request's inner scopes from touching anything else. */
#[CoversClass(ScopeConfinement::class)]
final class ScopeConfinementTest extends TelemetryTestCase
{
    #[Test]
    public function onlyTheTopScopeCanConfineWhatComesAfterIt(): void
    {
        $activation = $this->contextStorage->attach($this->contextStorage->current());
        $above = $this->contextStorage->attach($this->contextStorage->current());

        self::assertNull(ScopeConfinement::above($activation, $this->contextStorage, $this->reporter, 'request'));
        self::assertNull(ScopeConfinement::above(null, $this->contextStorage, $this->reporter, 'request'));

        $above->detach();
        $activation->detach();
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function aScopeThatStaysActiveIsReportedInsteadOfLoopingForever(): void
    {
        $activation = $this->createStub(ContextStorageScopeInterface::class);
        $stuck = $this->createStub(ContextStorageScopeInterface::class);
        $storage = $this->createStub(ContextStorageInterface::class);
        $storage->method('scope')->willReturn($activation, $stuck, $stuck);
        $confinement = ScopeConfinement::above($activation, $storage, $this->reporter, 'request');
        self::assertInstanceOf(ScopeConfinement::class, $confinement);

        $confinement->release();

        self::assertSame(1, $this->reporter->total());
        self::assertStringContainsString('inner context scope stayed active', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function aScopeThatCannotBeDetachedIsReportedNotThrown(): void
    {
        $activation = $this->createStub(ContextStorageScopeInterface::class);
        $broken = $this->createStub(ContextStorageScopeInterface::class);
        $broken->method('detach')->willThrowException(new \RuntimeException('storage is gone'));
        $storage = $this->createStub(ContextStorageInterface::class);
        $storage->method('scope')->willReturn($activation, $broken);
        $confinement = ScopeConfinement::above($activation, $storage, $this->reporter, 'request');
        self::assertInstanceOf(ScopeConfinement::class, $confinement);

        $confinement->release();

        self::assertSame(1, $this->reporter->total());
        self::assertStringContainsString('inner context release failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function anotherFiberReleasesNothing(): void
    {
        $this->useFiberBoundStorage();
        $activation = $this->contextStorage->attach($this->contextStorage->current());
        $confinement = ScopeConfinement::above($activation, $this->contextStorage, $this->reporter, 'request');
        self::assertInstanceOf(ScopeConfinement::class, $confinement);
        $inner = $this->contextStorage->attach($this->contextStorage->current());

        $storage = $this->contextStorage;
        $fiberScopeKept = null;
        new \Fiber(static function () use ($confinement, $storage, &$fiberScopeKept): void {
            $own = $storage->attach($storage->current());
            $confinement->release();
            $fiberScopeKept = $storage->scope() === $own;
            $own->detach();
        })->start();

        self::assertTrue($fiberScopeKept, "the fiber's own scopes are not the request's");
        self::assertSame($inner, $this->contextStorage->scope());
        $inner->detach();
        $activation->detach();
        $this->assertNoReports();
    }
}
