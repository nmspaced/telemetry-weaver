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
     * @param bool $durations whether this component records an operation-duration histogram,
     *                        and so has boundaries worth overriding; `runtime` reports state,
     *                        not durations
     *
     * @throws \RuntimeException the Config component's builder throws on internal misuse,
     *                           which would be a defect in this tree, not a runtime input
     */
    public static function component(
        string $name,
        string $info,
        bool $traces = true,
        ?string $overridableOption = null,
        bool $durations = true,
    ): ArrayNodeDefinition {
        $node = new ArrayNodeDefinition($name);

        $node->addDefaultsIfNotSet()->info($info);

        if ($traces) {
            self::signal($node, 'traces', 'Produce spans for this component.', $overridableOption);
        }

        self::signal($node, 'metrics', 'Record measurements for this component.', $overridableOption);

        if ($durations) {
            self::durationBuckets($node);
        }

        return $node;
    }

    /**
     * The histogram boundaries of this component's operation-duration instrument.
     *
     * The bundle's defaults follow the semantic conventions, and those are chosen to be
     * comparable across services rather than tight around any one of them. An application with
     * an SLO stated in single-digit milliseconds cannot see it in buckets whose second step is
     * 10 ms, and the OpenTelemetry answer — a view — is a heavier instrument than "these
     * numbers instead of those". Empty keeps the default.
     *
     * Seconds, because every default is in seconds and a histogram whose unit varies by
     * component cannot be compared across them.
     *
     * @throws \RuntimeException
     */
    private static function durationBuckets(ArrayNodeDefinition $component): void
    {
        $component
            ->children()
            ->arrayNode('duration_buckets')
            ->info(
                "Histogram bucket boundaries in seconds, replacing this component's defaults. Strictly increasing and greater than zero. Empty keeps the defaults.",
            )
            ->performNoDeepMerging()
            ->floatPrototype()
            ->end()
            ->defaultValue([])
            ->validate()
            ->ifTrue(self::isNotStrictlyIncreasing(...))
            ->thenInvalid('duration_buckets must be strictly increasing and greater than zero, got %s')
            ->end()
            ->end()
            ->end();
    }

    /**
     * The SDK does not reject unordered boundaries — it builds buckets from them as they are,
     * and the histogram silently becomes meaningless. Caught here, where the file that wrote
     * them can be named.
     */
    private static function isNotStrictlyIncreasing(mixed $boundaries): bool
    {
        if (!\is_array($boundaries)) {
            return true;
        }

        $previous = 0.0;
        /** @var mixed $boundary */
        foreach ($boundaries as $boundary) {
            if (!\is_float($boundary) && !\is_int($boundary)) {
                return true;
            }

            if ((float) $boundary <= $previous) {
                return true;
            }

            $previous = (float) $boundary;
        }

        return false;
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
