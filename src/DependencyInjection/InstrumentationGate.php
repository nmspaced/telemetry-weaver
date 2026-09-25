<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The preconditions a compiler pass has to clear before it registers anything.
 *
 * Every pass asks the same three kinds of question — is the bundle on and does this
 * component produce any signal, will the optional Symfony package still be installed in
 * the `--no-dev` build, and is the service to decorate actually in the container — and
 * each was spelling them out as one negated multi-line condition of its own. Four passes
 * in, the conditions had drifted: one had forgotten the global `traces.enabled`, one
 * repeated the parent-package list, and one asked `class_exists()` where the others
 * asked `willBeAvailable()`. Written as a chain the questions read in the order they are
 * decided, and a pass that forgets one is visibly shorter than the others.
 *
 * The gate is immutable and short-circuits: once closed it answers every further
 * question with itself, so the availability check is not paid for a component whose
 * signals are both off.
 *
 * Availability is `ContainerBuilder::willBeAvailable()` rather than `class_exists()`
 * because the two disagree exactly where it matters — a class can exist right now
 * because a dev dependency pulled it in, and only `willBeAvailable()` answers for the
 * install the container will run under.
 */
final readonly class InstrumentationGate
{
    /** @var list<string> the package whose dependency on an optional component is being judged */
    private const array PARENT_PACKAGES = ['nmspaced/telemetry-weaver'];

    private function __construct(
        private ContainerBuilder $container,
        private bool $open,
    ) {}

    /**
     * Open when the bundle itself is enabled.
     *
     * The starting point for every pass, including the parts that are not tied to one
     * component: a flush subscriber belongs to the bundle being on, not to any
     * component's signals.
     */
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

    /**
     * Stays open only if every named service is already defined.
     *
     * For passes that decorate something another bundle registers: the service may be
     * absent because that bundle is not installed, or because its own configuration
     * switched the feature off.
     */
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
     * Like {@see instruments()}, for a component whose wrapper also propagates context: it
     * stays open while the component's own switches are on, whatever the global signal
     * switches say. See {@see SignalSwitch::carriesContext()}.
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
