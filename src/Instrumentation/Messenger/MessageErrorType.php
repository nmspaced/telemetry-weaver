<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** @internal Classifies failures without changing the exception Messenger receives. */
final readonly class MessageErrorType
{
    /** @return non-empty-string */
    public static function of(\Throwable $error): string
    {
        while ($error instanceof HandlerFailedException) {
            $causes = $error->getWrappedExceptions();
            if (\count($causes) !== 1) {
                // Several handlers failed: choosing just one cause would mislabel the operation.
                break;
            }

            $error = \array_values($causes)[0];
        }

        // Anonymous class names contain a source location, unsuitable for metric labels.
        if (!\str_contains($error::class, '@anonymous')) {
            return $error::class;
        }

        /** @var class-string|false $parent */
        $parent = \get_parent_class($error);

        return $parent === false ? \Throwable::class : $parent;
    }
}
