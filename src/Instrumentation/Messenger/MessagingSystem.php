<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;

/**
 * `messaging.system` for a transport: the broker, when the transport object says which.
 *
 * Decided by the transport's class and nothing else. A DSN would name the broker too, but
 * it is also where the password is, it is usually an unresolved env placeholder at
 * compile time, and reading it at runtime would mean holding a secret to derive a label.
 * The class is known without I/O: a sender is the object being called, and a receiver
 * comes from `messenger.receiver_locator`, which a worker has already asked for the same
 * shared instance before any message arrives.
 *
 * Only transports whose broker has a value in the conventions are mapped — AMQP is
 * RabbitMQ, SQS is `aws_sqs`. Redis, Doctrine, Beanstalkd, in-memory and sync have no
 * such value and keep the framework fallback `symfony`, as does anything the locator
 * cannot produce. A transport is never guessed from its name: `rabbit` is a name someone
 * chose, not a fact about what it connects to.
 *
 * The bridge classes are referenced by name only — they are dev dependencies here, not
 * runtime ones. `is_a()` on an object whose class is unrelated never autoloads the
 * target, so an application without a bridge installed pays nothing and gets the
 * fallback. The map is small and fixed, and nothing is cached:
 * the lookup is one `is_a()` per mapped class, cheaper than the memory a per-transport
 * cache would need to justify.
 */
final readonly class MessagingSystem
{
    public const string FALLBACK = MessageAttributes::SYSTEM;

    /**
     * @var array<class-string, non-empty-string>
     */
    private const array KNOWN = [
        AmqpTransport::class => MessagingIncubatingAttributes::MESSAGING_SYSTEM_VALUE_RABBITMQ,
        AmazonSqsTransport::class => MessagingIncubatingAttributes::MESSAGING_SYSTEM_VALUE_AWS_SQS,
    ];

    /**
     * @param ContainerInterface|null $receivers `messenger.receiver_locator`, when Messenger has one
     * @param array<class-string, non-empty-string> $known transport class => system; replaced only in tests
     */
    public function __construct(
        private ?ContainerInterface $receivers = null,
        private array $known = self::KNOWN,
    ) {}

    /**
     * @return non-empty-string
     */
    public function ofTransport(object $transport): string
    {
        foreach ($this->known as $class => $system) {
            if (\is_a($transport, $class)) {
                return $system;
            }
        }

        return self::FALLBACK;
    }

    /**
     * @return non-empty-string
     */
    public function ofReceiver(string $name): string
    {
        try {
            if ($this->receivers === null || !$this->receivers->has($name)) {
                return self::FALLBACK;
            }

            /** @var mixed $receiver */
            $receiver = $this->receivers->get($name);
        } catch (\Throwable) {
            return self::FALLBACK;
        }

        return \is_object($receiver) ? $this->ofTransport($receiver) : self::FALLBACK;
    }
}
