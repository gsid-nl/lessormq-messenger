<?php

declare(strict_types=1);

namespace LessorMQ\Messenger;

use LessorMQ\ClaimOptions;
use LessorMQ\Client;
use LessorMQ\Exception\LeaseLostException;
use LessorMQ\Exception\LessorMQException;
use LessorMQ\Exception\NotFoundException;
use LessorMQ\Lease;
use LessorMQ\PermanentFailure;
use LessorMQ\PublishOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * A Messenger transport on one LessorMQ queue.
 *
 * It reports, the queue decides: a rejected envelope is failed with the exception's
 * class and message, and the queue's settings say whether and when it comes back. Set
 * `retry_strategy.max_retries: 0` on these transports so Messenger does not retry on top.
 * Only an UnrecoverableExceptionInterface (or LessorMQ\PermanentFailure) and a message
 * that cannot be decoded are reported as permanent.
 */
final class LessorMQTransport implements TransportInterface, KeepaliveReceiverInterface, MessageCountAwareInterface
{
    /** Marks a body that was not valid UTF-8 (PhpSerializer output) and travels base64-encoded */
    private const BASE64_HEADER = 'X-LessorMQ-Body-Encoding';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Client $client,
        private readonly SerializerInterface $serializer,
        private readonly string $queue,
        private readonly TransportOptions $options,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function get(): iterable
    {
        $route = $this->route();

        if ($route === false) {
            // A route is required (a VPN, say) and there is none right now: skip this round.
            return [];
        }

        try {
            $lease = $this->client->claim($this->queue, new ClaimOptions(
                $this->options->consumer,
                $route,
                $this->options->waitSeconds,
                $this->options->leaseSeconds,
            ));
        } catch (LessorMQException $e) {
            $this->logger->error('LessorMQ claim failed', ['queue' => $this->queue, 'error' => $e->getMessage()]);

            return [];
        }

        if ($lease === null) {
            return [];
        }

        try {
            $envelope = $this->serializer->decode($this->encoded($lease));
        } catch (MessageDecodingFailedException $e) {
            $this->report(fn() => $this->client->fail($lease, $e, true), $lease, 'fail');

            return [];
        }

        return [$envelope->with(
            new LessorMQReceivedStamp($lease),
            new TransportMessageIdStamp($lease->message->id),
        )];
    }

    public function ack(Envelope $envelope): void
    {
        $lease = $this->lease($envelope);
        $this->report(fn() => $this->client->ack($lease), $lease, 'ack');
    }

    public function reject(Envelope $envelope): void
    {
        $lease = $this->lease($envelope);
        $details = $envelope->last(ErrorDetailsStamp::class);
        $class = $details?->getExceptionClass();
        $permanent = $class !== null && (
            is_a($class, UnrecoverableExceptionInterface::class, true)
            || is_a($class, PermanentFailure::class, true)
        );

        $this->report(
            fn() => $this->client->fail(
                $lease,
                $details?->getExceptionMessage() ?: 'Rejected by Messenger',
                $permanent,
                null,
                $class,
            ),
            $lease,
            'fail',
        );
    }

    /**
     * Messenger passes its alarm interval (5 s by default), which is how often it calls
     * this, not how long the lease should last; the lease is renewed for the transport's
     * lease_seconds, or the queue's.
     */
    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        $lease = $this->lease($envelope);

        try {
            $this->client->extend($lease, $this->options->leaseSeconds);
        } catch (LeaseLostException $e) {
            $this->logger->warning('Lease lost while the handler ran', ['message_id' => $lease->message->id]);
        } catch (LessorMQException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    public function send(Envelope $envelope): Envelope
    {
        // Messenger's own retry would publish a copy and ack the original, so the queue
        // would never count the attempt. Retries are the queue's: say so loudly.
        $retried = $envelope->last(RedeliveryStamp::class) !== null;

        if ($retried && $envelope->last(LessorMQReceivedStamp::class) !== null) {
            throw new LogicException(
                'Messenger tried to retry a message from LessorMQ. Set retry_strategy.max_retries: 0 on '
                . 'LessorMQ transports: the queue decides about retries (see the queue settings).',
            );
        }

        $encoded = $this->serializer->encode($envelope);
        $headers = array_map('strval', $encoded['headers'] ?? []);
        $body = $encoded['body'];

        if (!mb_check_encoding($body, 'UTF-8')) {
            $headers[self::BASE64_HEADER] = 'base64';
            $body = base64_encode($body);
        } else {
            // A JSON body is stored as JSON, so the queue's messages can be read.
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : $body;
        }

        $delay = $envelope->last(DelayStamp::class)?->getDelay();

        try {
            $published = $this->client->publish($this->queue, $body, new PublishOptions(
                $headers,
                $envelope->last(DedupKeyStamp::class)?->key,
                $delay !== null && $delay > 0 ? (int) ceil($delay / 1000) : null,
                null,
                $envelope->last(MaxAttemptsStamp::class)?->maxAttempts,
            ));
        } catch (LessorMQException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return $envelope->with(new TransportMessageIdStamp($published->id));
    }

    public function getMessageCount(): int
    {
        try {
            $stats = $this->client->stats($this->queue);
        } catch (NotFoundException) {
            return 0;
        } catch (LessorMQException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return ($stats['ready'] ?? 0) + ($stats['delayed'] ?? 0);
    }

    /**
     * The envelope as the serializer expects it: the body as the string it encoded.
     *
     * @return array{body: string, headers: array<string, string>}
     */
    private function encoded(Lease $lease): array
    {
        $headers = $lease->message->headers;
        $body = $lease->message->body;

        if (($headers[self::BASE64_HEADER] ?? null) === 'base64' && is_string($body)) {
            unset($headers[self::BASE64_HEADER]);
            $body = (string) base64_decode($body, true);
        } elseif (!is_string($body)) {
            $body = (string) json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        }

        return ['body' => $body, 'headers' => $headers];
    }

    /**
     * @return string|false|null the route for this claim; false to skip it
     */
    private function route(): string|false|null
    {
        $route = $this->options->route;

        if ($this->options->routeFile !== null) {
            $content = is_readable($this->options->routeFile) ? @file_get_contents($this->options->routeFile) : false;
            $route = $content === false ? null : (trim($content) ?: null);
        }

        if ($route === null && $this->options->routeRequired) {
            return false;
        }

        return $route;
    }

    private function lease(Envelope $envelope): Lease
    {
        $stamp = $envelope->last(LessorMQReceivedStamp::class);

        if (!$stamp instanceof LessorMQReceivedStamp) {
            throw new LogicException('No LessorMQReceivedStamp: this envelope was not received from LessorMQ.');
        }

        return $stamp->lease;
    }

    /**
     * Ack and fail never take the worker down: a lost lease means the message is someone
     * else's now, and any other failure lets the lease expire so the message comes back.
     */
    private function report(callable $call, Lease $lease, string $what): void
    {
        try {
            $call();
        } catch (LeaseLostException) {
            $this->logger->warning('Lease lost before ' . $what . '; the message is no longer ours', [
                'message_id' => $lease->message->id,
            ]);
        } catch (LessorMQException $e) {
            $this->logger->error('LessorMQ ' . $what . ' failed; the lease will expire and the message come back', [
                'message_id' => $lease->message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
