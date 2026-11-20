<?php

declare(strict_types=1);

namespace LessorMQ\Messenger;

/**
 * The options of a lessormq:// transport, from its DSN query and its `options:`.
 */
final class TransportOptions
{
    private const KNOWN = [
        'queue', 'api_key', 'wait_seconds', 'lease_seconds', 'consumer', 'route', 'route_file',
        'route_required', 'http_retries',
    ];

    /**
     * @param string      $consumer      shown to administrators; the host name by default
     * @param string|null $route         a fixed route for every claim
     * @param string|null $routeFile     a file whose content is the route, read on every claim
     *                                   (a VPN that rotates, say); empty or missing means none
     * @param bool        $routeRequired skip claiming while there is no route
     */
    public function __construct(
        public readonly string $queue,
        public readonly string $apiKey,
        public readonly int $waitSeconds = 5,
        public readonly ?int $leaseSeconds = null,
        public readonly string $consumer = '',
        public readonly ?string $route = null,
        public readonly ?string $routeFile = null,
        public readonly bool $routeRequired = false,
        public readonly int $httpRetries = 3,
    ) {
    }

    /**
     * @param array<string, mixed> $options DSN query merged with the transport's options
     */
    public static function fromArray(array $options): self
    {
        unset($options['transport_name']);
        $unknown = array_diff(array_keys($options), self::KNOWN);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown LessorMQ transport option(s): %s. Known: %s.',
                implode(', ', $unknown),
                implode(', ', self::KNOWN),
            ));
        }

        foreach (['queue', 'api_key'] as $required) {
            if (!is_string($options[$required] ?? null) || $options[$required] === '') {
                throw new \InvalidArgumentException(sprintf(
                    'A LessorMQ transport needs "%s" (in the DSN or the options).',
                    $required,
                ));
            }
        }

        return new self(
            $options['queue'],
            $options['api_key'],
            (int) ($options['wait_seconds'] ?? 5),
            isset($options['lease_seconds']) ? (int) $options['lease_seconds'] : null,
            (string) ($options['consumer'] ?? (gethostname() ?: '')),
            isset($options['route']) && $options['route'] !== '' ? (string) $options['route'] : null,
            isset($options['route_file']) && $options['route_file'] !== '' ? (string) $options['route_file'] : null,
            filter_var($options['route_required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            (int) ($options['http_retries'] ?? 3),
        );
    }
}
