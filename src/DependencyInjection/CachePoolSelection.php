<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Which cache pools get instrumented.
 *
 * A pool is decorated once, for both signals at once: the decorator is one object and
 * cannot report to traces while staying silent for metrics. So the selection is one
 * list, not one per signal — unlike HTTP paths, where the two signals genuinely want
 * different answers.
 */
final readonly class CachePoolSelection
{
    /**
     * @param list<string> $selected
     * @param array<string, true> $excluded
     */
    private function __construct(
        private array $selected,
        private array $excluded,
        private bool $all,
    ) {}

    public static function fromContainer(ContainerBuilder $container): self
    {
        $selected = self::list($container, 'open_telemetry.instrumentation.cache.pools');
        $excluded = self::list($container, 'open_telemetry.instrumentation.cache.excluded_pools');

        return new self($selected, \array_fill_keys($excluded, true), \in_array('*', $selected, true));
    }

    public function includes(string $id): bool
    {
        if ($this->excluded[$id] ?? false) {
            return false;
        }

        return $this->all || \in_array($id, $this->selected, true);
    }

    /**
     * @return list<string>
     *
     * @throws \LogicException when the parameter is not a list of pool ids
     */
    private static function list(ContainerBuilder $container, string $parameter): array
    {
        if (!$container->hasParameter($parameter)) {
            return [];
        }

        $pools = $container->getParameter($parameter);

        if (!\is_array($pools)) {
            throw new \LogicException(\sprintf('Parameter "%s" must be an array.', $parameter));
        }

        /** @var list<string> */
        return \array_values(\array_filter($pools, \is_string(...)));
    }
}
