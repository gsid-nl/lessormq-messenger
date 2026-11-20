<?php

declare(strict_types=1);

namespace LessorMQ\Messenger;

use LessorMQ\Client;
use LessorMQ\ClientOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Creates transports from DSNs like
 *
 *   lessormq://<api-key>@mq.example.com/<queue>?wait_seconds=5
 *   lessormq+http://<api-key>@queue-svc/<queue>              plain HTTP, inside a cluster
 *
 * Every DSN query parameter can also be given under the transport's `options:`.
 *
 * @implements TransportFactoryInterface<LessorMQTransport>
 */
final class LessorMQTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $parts = parse_url($dsn);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('The LessorMQ DSN is not valid: it needs a host.');
        }

        parse_str($parts['query'] ?? '', $query);
        $fromDsn = array_filter([
            'queue' => isset($parts['path']) ? trim(rawurldecode($parts['path']), '/') : null,
            'api_key' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
        ], static fn(?string $value) => $value !== null && $value !== '');
        $transport = TransportOptions::fromArray($options + $query + $fromDsn);

        $scheme = $parts['scheme'] === 'lessormq+http' ? 'http' : 'https';
        $baseUrl = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $client = new Client(
            $baseUrl,
            $transport->apiKey,
            new ClientOptions(httpRetries: $transport->httpRetries, userAgent: 'lessormq-messenger/1'),
            $this->httpClient,
            null,
            null,
            $this->logger,
        );

        return new LessorMQTransport($client, $serializer, $transport->queue, $transport, $this->logger);
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'lessormq://')
            || str_starts_with($dsn, 'lessormq+http://')
            || str_starts_with($dsn, 'lessormq+https://');
    }
}
