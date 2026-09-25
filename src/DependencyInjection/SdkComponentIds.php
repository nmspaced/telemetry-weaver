<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Turns configured `sdk.*` service ids into references, rejecting ids that name no service or
 * the wrong kind of service. Checked at compile time so a wrong id fails the build.
 *
 * @internal
 */
final readonly class SdkComponentIds
{
    /**
     * @param non-empty-string $parameter naming a list of service ids
     * @param class-string $interface
     *
     * @return list<Reference>
     *
     * @throws InvalidArgumentException
     */
    public static function references(ContainerBuilder $container, string $parameter, string $interface): array
    {
        $ids = $container->getParameter($parameter);
        if (!\is_array($ids)) {
            return [];
        }

        $references = [];
        foreach (\array_keys($ids) as $index) {
            $references[] = self::reference($container, $parameter, $interface, $index);
        }

        return \array_values(\array_filter($references));
    }

    /**
     * A service whose class is unknown before it is built is accepted.
     *
     * @param non-empty-string $parameter
     * @param class-string $interface
     *
     * @throws InvalidArgumentException
     */
    public static function reference(
        ContainerBuilder $container,
        string $parameter,
        string $interface,
        int|string|null $index = null,
    ): ?Reference {
        $id = $container->getParameter($parameter);
        if ($index !== null) {
            /** @var mixed $id */
            $id = self::item($id, $index);
            $parameter = \sprintf('%s[%s]', $parameter, (string) $index);
        }

        if ($id === null) {
            return null;
        }

        if (!\is_string($id) || $id === '' || !$container->has($id)) {
            throw new InvalidArgumentException(\sprintf(
                '%s names "%s", which is not a service id.',
                $parameter,
                self::describe($id),
            ));
        }

        $class = DefinitionClass::of($container, $container->findDefinition($id));
        if (
            $class !== null
            && $container->getReflectionClass($class, false) !== null
            && !\is_a($class, $interface, true)
        ) {
            throw new InvalidArgumentException(\sprintf(
                '%s names "%s" (%s), which does not implement %s.',
                $parameter,
                $id,
                $class,
                $interface,
            ));
        }

        return new Reference($id);
    }

    private static function item(mixed $ids, int|string $index): mixed
    {
        if (!\is_array($ids)) {
            return null;
        }

        return $ids[$index] ?? null;
    }

    private static function describe(mixed $id): string
    {
        if (!\is_string($id)) {
            return \get_debug_type($id);
        }

        return $id;
    }
}
