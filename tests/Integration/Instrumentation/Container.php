<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/** @internal the smallest PSR-11 container Messenger's locators need */
final readonly class Container implements ContainerInterface
{
    /** @param array<string, mixed> $services */
    public function __construct(
        private array $services,
    ) {}

    #[\Override]
    public function get(string $id): mixed
    {
        return (
            $this->services[$id] ?? throw new class extends \RuntimeException implements NotFoundExceptionInterface {}
        );
    }

    #[\Override]
    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->services);
    }
}
