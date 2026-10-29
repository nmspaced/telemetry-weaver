<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\EventDispatcher\Event;

/** A listener with a method per outcome; `$probe` runs inside `onPlaced()`. */
final readonly class OrderListener
{
    private \Closure $probe;

    /** @param (\Closure(): void)|null $probe nothing by default: before PHP 8.5 a closure is no default value */
    public function __construct(?\Closure $probe = null)
    {
        $this->probe = $probe ?? static function (): void {};
    }

    public function onPlaced(): void
    {
        ($this->probe)();
    }

    public function stop(Event $event): void
    {
        $event->stopPropagation();
    }

    /** @throws \RuntimeException */
    public function fail(): never
    {
        throw new \RuntimeException('listener failed');
    }

    /** @throws NotFoundHttpException */
    public function missing(): never
    {
        throw new NotFoundHttpException('no such order');
    }
}
