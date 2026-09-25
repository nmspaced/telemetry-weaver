<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;

/**
 * Compiles the real bundle container, so tests catch wiring errors unit tests miss.
 *
 * @internal
 */
abstract class ContainerTestCase extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $_SERVER['OTEL_METRICS_EXPORTER'] = 'memory';
        $_SERVER['OTEL_TRACES_EXPORTER'] = 'memory';
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset($_SERVER['OTEL_METRICS_EXPORTER'], $_SERVER['OTEL_TRACES_EXPORTER']);
    }

    /**
     * @param array<string, mixed> $config
     * @param bool $exposeAll make every service public; false compiles as a kernel does
     *
     * @throws \Throwable
     */
    protected function compile(
        array $config = [],
        ?Definition $loopFactory = null,
        ?\Closure $configure = null,
        bool $exposeAll = true,
    ): ContainerBuilder {
        $bundle = new TelemetryWeaverBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.runtime_mode.worker', 1);
        $container->setParameter('kernel.runtime_mode.web', true);

        $container->register('logger', RecordingLogger::class);
        $container->register('event_dispatcher', EventDispatcher::class);
        $container->register('controller_resolver', ControllerResolver::class);
        $container
            ->register('http_kernel', HttpKernel::class)
            ->setArguments([new Reference('event_dispatcher'), new Reference('controller_resolver')]);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);

        $bundle->build($container);

        if ($loopFactory !== null) {
            $container->setDefinition('open_telemetry.metrics.loop_factory', $loopFactory);
        }

        $container->addCompilerPass(
            new readonly class($exposeAll) implements CompilerPassInterface {
                public function __construct(
                    private bool $exposeAll,
                ) {}

                #[\Override]
                public function process(ContainerBuilder $container): void
                {
                    foreach ($container->getDefinitions() as $definition) {
                        $definition->setPublic($this->exposeAll || $definition->isPublic());
                    }

                    foreach ($container->getAliases() as $alias) {
                        $alias->setPublic($this->exposeAll || $alias->isPublic());
                    }
                }
            },
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -100,
        );

        $configure?->__invoke($container);
        $container->compile();

        return $container;
    }
}
