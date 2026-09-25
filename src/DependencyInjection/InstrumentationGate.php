<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The preconditions a compiler pass checks before registering anything: the bundle and the
 * component are on, the optional package will be installed, and the services it needs exist.
 *
 * Immutable and short-circuiting. Uses `willBeAvailable()`, which answers for the `--no-dev`
 * install, not the current one.
 */
final readonly class InstrumentationGate
{
    /** @var list<string> */
    private const array PARENT_PACKAGES = ['nmspaced/telemetry-weaver'];

    private function __construct(
        private ContainerBuilder $container,
        private bool $open,
    ) {}

    /** Open when the bundle is enabled. */
    public static function bundle(ContainerBuilder $container): self
    {
        return new self($container, SignalSwitch::bundleEnabled($container));
    }

    /**
     * Stays open only if the optional package will be installed at runtime.
     *
     * @param non-empty-string $package a Composer package name, e.g. symfony/mailer
     * @param class-string $marker any class or interface that package owns
     */
    public function requires(string $package, string $marker): self
    {
        if (!$this->open) {
            return $this;
        }

        return new self($this->container, ContainerBuilder::willBeAvailable($package, $marker, self::PARENT_PACKAGES));
    }

    /** Stays open only if every named service is defined. */
    public function needs(string ...$serviceIds): self
    {
        if (!$this->open) {
            return $this;
        }

        return new self($this->container, \array_all($serviceIds, fn(string $id): bool => $this->container->has($id)));
    }

    /**
     * Stays open only if the component produces at least one signal.
     *
     * @param non-empty-string $component
     */
    public function instruments(string $component): self
    {
        if (!$this->open) {
            return $this;
        }

        return new self($this->container, SignalSwitch::instrumented($this->container, $component));
    }

    /**
     * Stays open while the component's own switches are on, whatever the global signal switches
     * say. For components that propagate context.
     *
     * @param non-empty-string $component
     */
    public function carriesContext(string $component): self
    {
        if (!$this->open) {
            return $this;
        }

        return new self($this->container, SignalSwitch::carriesContext($this->container, $component));
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function isClosed(): bool
    {
        return !$this->open;
    }
}
