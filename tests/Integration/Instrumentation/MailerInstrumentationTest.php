<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Mailer\MailerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Mailer\TraceableMailTransport;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\FrameworkInstrumentationTestCase;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Messenger\MessageHandler;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Transports;
use Symfony\Component\Messenger\Envelope as MessengerEnvelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\RawMessage;

/**
 * `TraceableMailTransport` behaviour: subject redaction, transport-name resolution for aggregate
 * transports, error propagation, and the queued-mail boundary where a message is only measured once
 * the messenger handler actually hands it to the real transport.
 */
final class MailerInstrumentationTest extends FrameworkInstrumentationTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function mailerRecordsTheActualSendWithoutSensitiveContent(): void
    {
        $transport = new TraceableMailTransport(
            new NullTransport(),
            new MailerTelemetry($this->telemetry, $this->reporter),
        );
        $email = new Email()
            ->from('sender@example.org')
            ->to('recipient@example.org')
            ->subject('private')
            ->text('secret');
        self::assertInstanceOf(SentMessage::class, $transport->send($email));
        self::assertSame('null://', (string) $transport);
        $span = $this->span();
        self::assertSame('mailer.send', $span->getName());
        self::assertNull($span->getAttributes()->get('email.subject'));
        $encoded = \json_encode($span->getAttributes()->toArray());
        self::assertIsString($encoded);
        self::assertStringNotContainsString('secret', $encoded);
        self::assertSame('mailer.send.duration', $this->measurement()->name);
    }

    /** @throws \Throwable */
    #[Test]
    public function mailerSubjectIsOptInAndTransportFailureIsRethrownUnchanged(): void
    {
        $error = new \RuntimeException('send failed');
        $delegate = new readonly class($error) implements TransportInterface {
            public function __construct(
                private \Throwable $error,
            ) {}

            /** @throws \Throwable */
            #[\Override]
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw $this->error;
            }

            #[\Override]
            public function __toString(): string
            {
                return 'test://';
            }
        };
        $transport = new TraceableMailTransport(
            $delegate,
            new MailerTelemetry($this->telemetry, $this->reporter, recordSubject: true),
        );
        try {
            $transport->send(new Email()->subject('opted-in'));
            self::fail();
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($error, $runtimeException);
        }

        self::assertSame('opted-in', $this->span()->getAttributes()->get('email.subject'));
        self::assertSame(StatusCode::STATUS_ERROR, $this->span()->getStatus()->getCode());
        self::assertNull($this->telemetry->activeTrace());
    }

    /** @throws \Throwable */
    #[Test]
    public function queuedMailIsMeasuredOnlyWhenTheMessengerHandlerSendsIt(): void
    {
        $transport = new TraceableMailTransport(
            new NullTransport(),
            new MailerTelemetry($this->telemetry, $this->reporter),
        );
        /** @var SendEmailMessage|null $queued */
        $queued = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (SendEmailMessage $message) use (&$queued): MessengerEnvelope {
                $queued = $message;

                return new MessengerEnvelope($message);
            });
        $mailer = new Mailer($transport, $bus);
        $mailer->send(
            new Email()
                ->from('a@example.org')
                ->to('b@example.org')
                ->text('body'),
        );
        self::assertSame([], $this->telemetry->spans());
        self::assertNotNull($queued);
        (new MessageHandler($transport))($queued);
        self::assertCount(1, $this->telemetry->spans());
    }

    /** @throws \Throwable */
    #[Test]
    public function theTransportThatRanIsNamedFromTheMessageNotFromTheAggregate(): void
    {
        $transports = new Transports([
            'main' => new NullTransport(),
            'newsletter' => new NullTransport(),
        ]);
        $transport = new TraceableMailTransport($transports, new MailerTelemetry($this->telemetry, $this->reporter));

        $chosen = new Email()
            ->from('a@example.org')
            ->to('b@example.org')
            ->text('body');
        $chosen->getHeaders()->addTextHeader('X-Transport', 'newsletter');
        $transport->send($chosen);

        $transport->send(
            new Email()
                ->from('a@example.org')
                ->to('b@example.org')
                ->text('body'),
        );

        self::assertSame('newsletter', $this->span(0)->getAttributes()->get('mailer.transport'));
        self::assertSame('default', $this->span(1)->getAttributes()->get('mailer.transport'));
        self::assertSame(
            ['newsletter', 'default'],
            \array_map(
                /** @param array{attributes: array<array-key, mixed>} $point */
                static fn(array $point): mixed => $point['attributes']['mailer.transport'] ?? Assert::fail(
                    'missing mailer.transport attribute',
                ),
                self::histogramPoints($this->telemetry->measurements(), 'mailer.send.duration'),
            ),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aRawMessageOrAMissingSubjectFallsBackQuietly(): void
    {
        $mailer = new MailerTelemetry($this->telemetry, $this->reporter, recordSubject: true);

        self::assertSame('sent', $mailer->send(new RawMessage('raw'), static fn(): string => 'sent'));
        $mailer->send(new Email(), static fn(): null => null);

        self::assertSame('default', $this->span(0)->getAttributes()->get('mailer.transport'));
        self::assertFalse($this->span(0)->getAttributes()->has('email.subject'));
        self::assertFalse($this->span(1)->getAttributes()->has('email.subject'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnreadableMessageIsReportedAndStillSent(): void
    {
        $logger = new RecordingLogger();
        $mailer = new MailerTelemetry(
            $this->telemetry,
            new InstrumentationFailureReporter($logger),
            recordSubject: true,
        );
        $broken = new class() extends Email {
            #[\Override]
            public function getHeaders(): Headers
            {
                throw new \LogicException('headers are broken');
            }

            #[\Override]
            public function getSubject(): ?string
            {
                throw new \LogicException('subject is broken');
            }
        };

        self::assertSame('sent', $mailer->send($broken, static fn(): string => 'sent'));

        self::assertSame('default', $this->span()->getAttributes()->get('mailer.transport'));
        self::assertFalse($this->span()->getAttributes()->has('email.subject'));
        self::assertSame(
            [
                'OpenTelemetry lifecycle: Mail transport name extraction failed at "mailer.send": headers are broken (1 total in this process)',
                'OpenTelemetry lifecycle: Mail subject extraction failed at "mailer.send": subject is broken (2 total in this process)',
            ],
            $logger->messages(),
        );
    }
}
