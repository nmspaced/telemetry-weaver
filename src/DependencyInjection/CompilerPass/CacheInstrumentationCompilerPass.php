<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\CachePoolSelection;
use Nmspaced\TelemetryWeaver\DependencyInjection\DecoratedService;
use Nmspaced\TelemetryWeaver\DependencyInjection\DefinitionClass;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\DependencyInjection\TraceablePoolClass;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\CacheTelemetry;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final readonly class CacheInstrumentationCompilerPass implements CompilerPassInterface
{
    private const string CACHE_POOL_TAG = 'cache.pool';

    private const int DECORATION_PRIORITY = -32;

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)
            ->requires('symfony/cache', AdapterInterface::class)
            ->instruments('cache');

        if ($gate->isClosed()) {
            return;
        }

        $selection = CachePoolSelection::fromContainer($container);

        foreach ($container->findTaggedServiceIds(self::CACHE_POOL_TAG) as $id => $tags) {
            if (!$selection->includes($id)) {
                continue;
            }

            $definition = $container->getDefinition($id);

            if ($definition->isAbstract()) {
                continue;
            }

            $class = DefinitionClass::of($container, $definition);

            if ($class === null || !\is_a($class, AdapterInterface::class, true)) {
                continue;
            }

            /** @var list<array<string, mixed>> $tags */
            $poolName = $this->resolvePoolName($id, $tags);

            $innerId = DecoratedService::innerId($id);

            $container
                ->register(DecoratedService::id($id), TraceablePoolClass::for($class))
                ->setDecoratedService($id, $innerId, self::DECORATION_PRIORITY)
                ->setArgument('$delegate', new Reference($innerId))
                ->setArgument('$cacheTelemetry', new Reference(CacheTelemetry::class))
                ->setArgument('$poolName', $poolName);
        }
    }

    /**
     * @param list<array<string, mixed>> $tags
     */
    private function resolvePoolName(string $id, array $tags): string
    {
        foreach ($tags as $tag) {
            /** @var mixed $name */
            $name = $tag['name'] ?? null;

            if (\is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $id;
    }
}
