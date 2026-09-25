<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;

/**
 * `messaging.system` for a transport, decided by its class alone (never the DSN, which holds
 * secrets). AMQP maps to `rabbitmq`, SQS to `aws_sqs`; everything else is `symfony`.
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

        if (!\is_object($receiver)) {
            return self::FALLBACK;
        }

        return $this->ofTransport($receiver);
    }
}
