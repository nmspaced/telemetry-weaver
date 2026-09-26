<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit;

use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Booting the bundle is best-effort towards its own services: a broken one must not stop the kernel. */
#[CoversClass(TelemetryWeaverBundle::class)]
final class TelemetryWeaverBundleTest extends TestCase
{
    #[Test]
    public function thePackageRootHoldsTheBundlesConfiguration(): void
    {
        self::assertFileExists(new TelemetryWeaverBundle()->getPath() . '/config/definition.php');
    }

    /** @throws \Throwable */
    #[Test]
    public function servicesThatCannotBeBuiltAtBootAreSkipped(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('hasParameter')->willReturn(true);
        $container->method('getParameter')->willReturn(true);
        $container->method('get')->willThrowException(new \RuntimeException('service cannot be built'));
        $bundle = new TelemetryWeaverBundle();
        $bundle->setContainer($container);

        $bundle->boot();

        $this->addToAssertionCount(1);
    }
}
