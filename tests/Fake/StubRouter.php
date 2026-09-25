<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/** A router that returns a fixed route collection, or throws. */
final readonly class StubRouter implements RouterInterface
{
    private RouteCollection $collection;

    /**
     * @param array<string, string> $routes route name to path
     */
    public function __construct(
        array $routes = [],
        private ?\Throwable $failure = null,
    ) {
        $this->collection = new RouteCollection();

        foreach ($routes as $name => $path) {
            $this->collection->add($name, new Route($path));
        }
    }

    public static function failing(\Throwable $failure): self
    {
        return new self([], $failure);
    }

    /**
     * @throws \Throwable when the stub was built to fail
     */
    #[\Override]
    public function getRouteCollection(): RouteCollection
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->collection;
    }

    #[\Override]
    public function setContext(RequestContext $context): void {}

    #[\Override]
    public function getContext(): RequestContext
    {
        return new RequestContext();
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function generate(
        string $name,
        array $parameters = [],
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH,
    ): string {
        return '/';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function match(string $pathinfo): array
    {
        return [];
    }
}
