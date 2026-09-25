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
 * The Doctrine middleware is registered before DoctrineBundle collects `doctrine.middleware`, so it
 * actually ends up in every connection's driver chain.
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
            $container->addCompilerPass($collector, PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        });

        self::assertTrue(
            $collector->middlewareWasDefined,
            'the middleware is registered too late for DoctrineBundle to attach it to a connection',
        );
    }
}
