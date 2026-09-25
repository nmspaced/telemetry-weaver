<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * A component node with its `traces` and `metrics` switches, each accepting `true`, `false` or
 * `{enabled: bool}`.
 *
 * An autoloaded class because `config/definition.php` is included on every compile and cannot
 * declare named classes.
 *
 * @internal
 */
final class ComponentSignal
{
    private function __construct() {}

    /**
     * @param bool $traces whether the component can produce spans at all
     * @param non-empty-string|null $overridableOption a component-wide list either signal may override
     * @param bool $durations whether the component records an operation-duration histogram
     *
     * @throws \RuntimeException on misuse of the Config builder
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
     * Histogram boundaries for the component's operation duration, in seconds; empty keeps the default.
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

    /** The SDK accepts unordered boundaries silently, so they are rejected here. */
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

    /** @throws \RuntimeException */
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
