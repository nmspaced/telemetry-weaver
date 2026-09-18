<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Mailer;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * One transport invocation, including its failover attempts.
 *
 * There is no semantic convention for sending mail, so the attributes are the bundle's
 * own `mailer.*`. What is deliberately absent matters more than what is there:
 * recipients, body, headers and the transport DSN are never read, because a DSN carries
 * credentials and everything else carries the message. The subject is the one piece of
 * message content that can be recorded, it is opt-in, and it goes on the span only —
 * putting user-generated text into a metric label would make the timeseries unbounded
 * on top of leaking it.
 *
 * The transport is identified by the name the message asked for, not by the class of the
 * decorated service: the decorated service is the aggregate, so its class is the same
 * `Transports` for every send and says nothing. The name has to be read before
 * delegating, because the aggregate consumes the header on its way to the chosen
 * transport.
 */
final readonly class MailerTelemetry
{
    /** @var non-empty-string the name reported when the message did not choose a transport */
    private const string DEFAULT_TRANSPORT = 'default';

    private const string TRANSPORT_HEADER = 'X-Transport';

    private const string OPERATION = 'mailer.send';

    private Duration $duration;

    public function __construct(
        private Telemetry $telemetry,
        private InstrumentationFailureReporter $reporter,
        private bool $recordSubject = false,
        OperationBuckets $buckets = DefaultBuckets::Mail,
    ) {
        $this->duration = $telemetry->metrics()->duration(
            'mailer.send.duration',
            $buckets->unit(),
            $buckets->boundaries(),
            'Duration of sending a message through a mail transport.',
        );
    }

    /**
     * @template T
     *
     * @param \Closure(): T $send
     *
     * @return T
     *
     * @throws \Throwable whatever the transport threw
     */
    public function send(RawMessage $message, \Closure $send): mixed
    {
        $attributes = ['mailer.transport' => $this->transportName($message)];

        return $this->telemetry
            ->operation(self::OPERATION)
            ->kind(SpanKind::Client)
            ->attributes($attributes + $this->subject($message))
            ->duration($this->duration, attributes: $attributes)
            ->run(static fn(): mixed => $send());
    }

    /**
     * @return non-empty-string
     */
    private function transportName(RawMessage $message): string
    {
        try {
            if (!$message instanceof Message) {
                return self::DEFAULT_TRANSPORT;
            }

            /** @var mixed $name */
            $name = $message->getHeaders()->get(self::TRANSPORT_HEADER)?->getBody();

            return \is_string($name) && $name !== '' ? $name : self::DEFAULT_TRANSPORT;
        } catch (\Throwable $throwable) {
            $this->reporter->report('Mail transport name extraction failed', self::OPERATION, $throwable);

            return self::DEFAULT_TRANSPORT;
        }
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function subject(RawMessage $message): array
    {
        if (!$this->recordSubject || !$message instanceof Email) {
            return [];
        }

        try {
            $subject = $message->getSubject();

            return $subject === null || $subject === '' ? [] : ['email.subject' => $subject];
        } catch (\Throwable $throwable) {
            $this->reporter->report('Mail subject extraction failed', self::OPERATION, $throwable);

            return [];
        }
    }
}
