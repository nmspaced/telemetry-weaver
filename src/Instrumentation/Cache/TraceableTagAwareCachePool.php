<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Cache;

use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

readonly class TraceableTagAwareCachePool extends TraceableCachePool implements
    TagAwareAdapterInterface,
    TagAwareCacheInterface
{
    public function __construct(
        TagAwareAdapterInterface $delegate,
        CacheTelemetry $cacheTelemetry,
        string $poolName = 'cache.app',
    ) {
        parent::__construct($delegate, $cacheTelemetry, $poolName);
    }

    /**
     * @param array<array-key, string> $tags
     * @throws \Throwable
     */
    #[\Override]
    public function invalidateTags(array $tags): bool
    {
        $delegate = $this->delegate;

        if (!$delegate instanceof TagAwareAdapterInterface) {
            throw new \InvalidArgumentException(\sprintf(
                'Cannot call "%s::invalidateTags()": the inner pool does not implement "%s".',
                \get_debug_type($delegate),
                TagAwareAdapterInterface::class,
            ));
        }

        return $this->run(
            __FUNCTION__,
            ['cache.tags' => \array_values($tags)],
            /** @throws InvalidArgumentException */
            static fn(): bool => $delegate->invalidateTags($tags),
        );
    }
}
