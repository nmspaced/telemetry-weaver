<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Controller;

use Nmspaced\TelemetryWeaver\Instrumentation\CodeFunction;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;

/**
 * One operation per argument resolution, so the queries a value resolver makes (an entity
 * looked up by id, say) sit under it rather than directly under the server span.
 *
 * @internal
 */
final readonly class TraceableArgumentResolver implements ArgumentResolverInterface
{
    public function __construct(
        private ArgumentResolverInterface $delegate,
        private ControllerTelemetry $controllerTelemetry,
    ) {}

    /**
     * @return array<mixed>
     *
     * @throws \Throwable
     */
    #[\Override]
    public function getArguments(
        Request $request,
        callable $controller,
        ?\ReflectionFunctionAbstract $reflector = null,
    ): array {
        if (!$this->controllerTelemetry->isTracing()) {
            return $this->delegate->getArguments($request, $controller, $reflector);
        }

        return $this->controllerTelemetry->arguments(
            CodeFunction::of($controller),
            /**
             * @return array<mixed>
             *
             * @throws \Throwable
             */
            fn(): array => $this->delegate->getArguments($request, $controller, $reflector),
        );
    }
}
