<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Cache;

use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

final readonly class TraceableNamespacedCachePool extends TraceableCachePool implements NamespacedPoolInterface
{
    public function __construct(
        AdapterInterface&NamespacedPoolInterface $delegate,
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

        if (!$delegate instanceof NamespacedPoolInterface) {
            throw new \BadMethodCallException(\sprintf(
                'Cannot call "%s::withSubNamespace()": the inner pool does not implement "%s".',
                \get_debug_type($delegate),
                NamespacedPoolInterface::class,
            ));
        }

        return new self($delegate->withSubNamespace($namespace), $this->cacheTelemetry, $this->poolName);
    }
}
