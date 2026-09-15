<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * A component with its two signal switches, shared by every instrumented component's config
 * tree so each accepts `true`, `false` or `{enabled: bool}` the same way.
 *
 * Lives here, as a normally-autoloaded class, rather than as a method on the anonymous class
 * `config/definition.php` returns: that file is `include`d (not `include_once`) by Symfony's
 * `DefinitionFileLoader` on every container compile, so a named class declared inside it would
 * fatal with "Cannot redeclare" the second time a test compiles the container in the same
 * process. Splitting the tree-building logic out into real, autoloaded classes is what let the
 * anonymous class shrink enough to satisfy mago's `too-many-methods` lint rule without an
 * `@mago-expect` suppression.
 *
 * @internal
 */
final class ComponentSignal
{
    private function __construct() {}

    /**
     * @param bool $traces whether this component can produce spans at all; `runtime` is
     *                     the one that cannot, and offering it a switch that does nothing
     *                     would be worse than not offering one
     * @param non-empty-string|null $overridableOption name of a component-wide list (added by
     *                                                 the caller outside this method) that
     *                                                 either signal may override; absent
     *                                                 means "use the component's"
     *
     * @throws \RuntimeException the Config component's builder throws on internal misuse,
     *                           which would be a defect in this tree, not a runtime input
     */
    public static function component(
        string $name,
        string $info,
        bool $traces = true,
        ?string $overridableOption = null,
    ): ArrayNodeDefinition {
        $node = new ArrayNodeDefinition($name);

        $node->addDefaultsIfNotSet()->info($info);

        if ($traces) {
            self::signal($node, 'traces', 'Produce spans for this component.', $overridableOption);
        }

        self::signal($node, 'metrics', 'Record measurements for this component.', $overridableOption);

        return $node;
    }

    /**
     * Building the override option here, inside the same node the signal itself creates, is
     * what lets it be typed as the concrete `ArrayNodeDefinition` `arrayNode()` returns instead
     * of the widened `NodeDefinition` `find()` would hand back for a lookup done afterward.
     *
     * @throws \RuntimeException
     */
    private static function signal(
        ArrayNodeDefinition $component,
        string $signal,
        string $info,
        ?string $overridableOption,
    ): void {
        $signalNode = $component->children()->arrayNode($signal);
        $signalNode
            ->addDefaultsIfNotSet()
            ->info($info)
            ->beforeNormalization()
            ->ifTrue(static fn(mixed $value): bool => \is_bool($value))
            ->then(
                /** @return array{enabled: bool} */
                static fn(bool $value): array => ['enabled' => $value],
            )
            ->end();

        $signalChildren = $signalNode->children();
        $signalChildren->booleanNode('enabled')->defaultTrue();

        if ($overridableOption !== null) {
            $signalChildren
                ->arrayNode($overridableOption)
                ->performNoDeepMerging()
                ->info(\sprintf('Overrides the component-wide %s for this signal only.', $overridableOption))
                ->stringPrototype()
                ->cannotBeEmpty()
                ->end()
                ->defaultNull();
        }
    }
}
