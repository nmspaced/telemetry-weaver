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
 * The service graph is only ever exercised by a real compile: unit tests
 * construct these classes directly and would not notice a circular reference,
 * a missing parameter, or an argument wired to the wrong service.
 *
 * @internal
 */
abstract class ContainerTestCase extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        // Without a working exporter the provider degrades to noop, which would
        // hide every wiring question these tests are asking.
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
     * @param bool $exposeAll make every service public for the test to reach; false compiles the
     *                        container as a kernel does, private services removed or inlined
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
        // Normally supplied by FrameworkBundle; the HTTP config reads them.
        $container->setParameter('kernel.build_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.runtime_mode.worker', 1);
        $container->setParameter('kernel.runtime_mode.web', true);
        // A real, non-synthetic http_kernel: without one, a decoration of a
        // missing service is silently dropped and takes its defects with it.
        // FrameworkBundle always provides these two; the bundle's graph references
        // them, so a bare ContainerBuilder has to stand them up to compile at all.
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

        // Everything here is private, so an untouched compile would inline or
        // drop the very definitions under test.
        //
        // Runs last among the before-optimization passes (a priority below the -20 of
        // the bundle's own instrumentation passes): those passes register definitions
        // themselves, and a pass that ran first would never see them.
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
