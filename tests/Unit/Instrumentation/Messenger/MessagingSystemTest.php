<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessagingSystem;
use Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

#[CoversClass(MessagingSystem::class)]
final class MessagingSystemTest extends TestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function transports(): iterable
    {
        yield 'amqp is rabbitmq' => [AmqpTransport::class, 'rabbitmq'];
        yield 'sqs is aws_sqs' => [AmazonSqsTransport::class, 'aws_sqs'];
        yield 'in-memory has no broker' => [InMemoryTransport::class, 'symfony'];
        yield 'sync has no broker' => [SyncTransport::class, 'symfony'];
    }

    /**
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    #[Test]
    #[DataProvider('transports')]
    public function aTransportIsIdentifiedByItsClass(string $class, string $system): void
    {
        $transport = new \ReflectionClass($class)->newInstanceWithoutConstructor();

        self::assertSame($system, new MessagingSystem()->ofTransport($transport));
        self::assertSame($system, new MessagingSystem(new Container(['queue' => $transport]))->ofReceiver('queue'));
    }

    #[Test]
    public function aReceiverTheLocatorCannotProduceKeepsTheFallback(): void
    {
        self::assertSame('symfony', new MessagingSystem()->ofReceiver('async'));
        self::assertSame('symfony', new MessagingSystem(new Container([]))->ofReceiver('async'));
    }

    /** @throws \Throwable */
    #[Test]
    public function aLocatorThatFailsOrHoldsNoObjectKeepsTheFallback(): void
    {
        $failing = $this->createStub(ContainerInterface::class);
        $failing->method('has')->willReturn(true);
        $failing->method('get')->willThrowException(new \RuntimeException('receiver cannot be built'));

        self::assertSame('symfony', new MessagingSystem($failing)->ofReceiver('async'));
        self::assertSame('symfony', new MessagingSystem(new Container(['async' => 'dsn']))->ofReceiver('async'));
    }
}
