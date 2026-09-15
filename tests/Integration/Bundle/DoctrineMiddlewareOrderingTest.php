<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrineMiddleware;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tagging the middleware is not enough: DoctrineBundle collects `doctrine.middleware`
 * in its own compiler pass, registered with `addCompilerPass(new MiddlewaresPass())`
 * — before-optimization, priority 0 — and turns the tagged ids into a fixed
 * `setMiddlewares()` call per connection. A middleware registered after that pass has
 * run keeps its tag and never reaches a connection: `debug:container --tag` lists it
 * while the driver chain does not contain it.
 *
 * This test stands in for that pass at the same point in the pipeline.
 */
final class DoctrineMiddlewareOrderingTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theMiddlewareExistsBeforeDoctrineCollectsTaggedMiddlewares(): void
    {
        $collector = new class implements CompilerPassInterface {
            public ?bool $middlewareWasDefined = null;

            #[\Override]
            public function process(ContainerBuilder $container): void
            {
                $this->middlewareWasDefined ??= $container->hasDefinition(DoctrineMiddleware::class);
            }
        };

        $this->compile(configure: static function (ContainerBuilder $container) use ($collector): void {
            // The priority DoctrineBundle's MiddlewaresPass runs at.
            $container->addCompilerPass($collector, PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        });

        self::assertTrue(
            $collector->middlewareWasDefined,
            'the middleware is registered too late for DoctrineBundle to attach it to a connection',
        );
    }
}
