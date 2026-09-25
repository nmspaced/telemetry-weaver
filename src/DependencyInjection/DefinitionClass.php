<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The class a definition builds, known at compile time; child definitions inherit it from
 * their parents.
 *
 * @internal
 */
final readonly class DefinitionClass
{
    /** @return non-empty-string|null null when no definition in the chain names a class */
    public static function of(ContainerBuilder $container, Definition $definition): ?string
    {
        while ($definition->getClass() === null && $definition instanceof ChildDefinition) {
            $definition = $container->findDefinition($definition->getParent());
        }

        /** @var mixed $class */
        $class = $container->getParameterBag()->resolveValue($definition->getClass());

        if (!\is_string($class) || $class === '') {
            return null;
        }

        return $class;
    }
}
