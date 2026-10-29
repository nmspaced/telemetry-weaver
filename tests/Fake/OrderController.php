<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A controller with a method per outcome; `$probe` runs inside each call. */
final readonly class OrderController
{
    private \Closure $probe;

    /** @param (\Closure(): void)|null $probe nothing by default: before PHP 8.5 a closure is no default value */
    public function __construct(?\Closure $probe = null)
    {
        $this->probe = $probe ?? static function (): void {};
    }

    public function show(int $id): Response
    {
        ($this->probe)();

        return new Response((string) $id);
    }

    /** @return array{id: int} left to a `kernel.view` listener */
    #[Cache(maxage: 60)]
    public function view(int $id): array
    {
        ($this->probe)();

        return ['id' => $id];
    }

    /** @throws \RuntimeException */
    public function fail(): never
    {
        throw new \RuntimeException('controller failed');
    }

    /** @throws NotFoundHttpException */
    public function missing(): never
    {
        throw new NotFoundHttpException('no such order');
    }

    public static function index(): Response
    {
        return new Response();
    }

    public function __invoke(): Response
    {
        return new Response();
    }
}
