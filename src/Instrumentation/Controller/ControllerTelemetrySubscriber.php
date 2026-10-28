<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Controller;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wraps the controller the kernel is about to call, once its arguments are final.
 *
 * Not on `kernel.controller`: argument resolution reflects on the controller, and would see the
 * wrapper's signature instead. The original controller is put back as soon as the call returns
 * or throws, because `kernel.view` and `kernel.exception` listeners read the event's reflector,
 * for named arguments among other things. Its attributes are handed over both ways, so neither
 * swap makes Symfony read them off the wrapper.
 *
 * @internal
 */
final readonly class ControllerTelemetrySubscriber implements EventSubscriberInterface
{
    /** Last, so the controller wrapped is the one the kernel calls. */
    private const int PRIORITY = -2048;

    public function __construct(
        private ControllerTelemetry $controllerTelemetry,
    ) {}

    /**
     * @return array<string, array{string, int}>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', self::PRIORITY]];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $controller = $event->getController();
        $attributes = $event->getAttributes('*');
        $function = ControllerFunction::of($controller);

        $event->setController(
            /** @throws \Throwable */
            function (mixed ...$arguments) use ($event, $controller, $attributes, $function): mixed {
                try {
                    return $this->controllerTelemetry->call(
                        $function,
                        // HttpKernel is not a strict_types file, so '7' reaches an int parameter
                        // when it calls the controller. Called from here, `$controller(...)` and
                        // even `\call_user_func_array()` (compiled into a direct call) would be
                        // strict; invokeArgs() calls from an internal frame, which coerces.
                        /** @throws \Throwable */
                        static fn(): mixed => new \ReflectionFunction($controller(...))->invokeArgs($arguments),
                    );
                } finally {
                    $event->setController($controller, $attributes);
                }
            },
            $attributes,
        );
    }
}
