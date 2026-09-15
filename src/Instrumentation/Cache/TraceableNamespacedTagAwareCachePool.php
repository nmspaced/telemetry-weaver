<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Cache;

use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

final readonly class TraceableNamespacedTagAwareCachePool extends TraceableTagAwareCachePool implements
    NamespacedPoolInterface
{
    public function __construct(
        TagAwareAdapterInterface&NamespacedPoolInterface $delegate,
        CacheTelemetry $cacheTelemetry,
        string $poolName = 'cache.app',
    ) {
        parent::__construct($delegate, $cacheTelemetry, $poolName);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[\Override]
    // @mago-expect lint:redundant-static — required by NamespacedPoolInterface
    public function withSubNamespace(string $namespace): static
    {
        $delegate = $this->delegate;

        if (!$delegate instanceof TagAwareAdapterInterface || !$delegate instanceof NamespacedPoolInterface) {
            throw new \InvalidArgumentException(\sprintf(
                'Cannot call "%s::withSubNamespace()": the inner pool must implement both "%s" and "%s".',
                \get_debug_type($delegate),
                TagAwareAdapterInterface::class,
                NamespacedPoolInterface::class,
            ));
        }

        return new self($delegate->withSubNamespace($namespace), $this->cacheTelemetry, $this->poolName);
    }
}
