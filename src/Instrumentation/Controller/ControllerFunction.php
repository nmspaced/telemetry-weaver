<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Controller;

/**
 * The name a controller callable is known by: `code.function.name` in full, and the span name
 * without the namespace.
 *
 * Closures have no name of their own, so they are named after the class they were declared in.
 * A per-closure name would carry the file and line, and a span name is not a stack trace.
 *
 * @internal
 */
final readonly class ControllerFunction
{
    private const string CLOSURE = '{closure}';

    /**
     * @param non-empty-string $name
     * @param non-empty-string $spanName
     */
    private function __construct(
        public string $name,
        public string $spanName,
    ) {}

    public static function of(callable $controller): self
    {
        if ($controller instanceof \Closure) {
            return self::closure($controller);
        }

        if (\is_array($controller)) {
            [$target, $method] = $controller;

            return self::method(\is_object($target) ? \get_debug_type($target) : $target, $method);
        }

        if (\is_object($controller)) {
            return self::method(\get_debug_type($controller), '__invoke');
        }

        if (!\is_string($controller)) {
            return self::named(self::CLOSURE, self::CLOSURE);
        }

        $separator = \strpos($controller, '::');

        if ($separator !== false) {
            return self::method(\substr($controller, 0, $separator), \substr($controller, $separator + 2));
        }

        return self::named($controller, $controller);
    }

    private static function closure(\Closure $controller): self
    {
        // @mago-expect analysis:unhandled-thrown-type — reflecting a Closure cannot fail
        $function = new \ReflectionFunction($controller);
        $method = $function->isAnonymous() ? self::CLOSURE : $function->getName();
        $class = $function->getClosureCalledClass()?->getName();

        return $class === null ? self::named($method, $method) : self::method($class, $method);
    }

    private static function method(string $class, string $method): self
    {
        $separator = \strrpos($class, '\\');
        $shortClass = $separator === false ? $class : \substr($class, $separator + 1);

        return self::named(\sprintf('%s::%s', $class, $method), \sprintf('%s::%s', $shortClass, $method));
    }

    private static function named(string $name, string $spanName): self
    {
        return new self($name === '' ? self::CLOSURE : $name, $spanName === '' ? self::CLOSURE : $spanName);
    }
}
