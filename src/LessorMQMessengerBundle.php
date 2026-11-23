<?php

declare(strict_types=1);

namespace LessorMQ\Messenger;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers the lessormq:// transport factory. Every transport gets an HTTP client of its
 * own (discovered: Symfony HttpClient's, say; lessormq/client brings the PSR-7 messages),
 * not the app's: Messenger's keepalive runs from a signal handler while the handler may be
 * in the middle of a request of its own, and Symfony HttpClient refuses a second request
 * on a client that is still processing one ("Userland callback cannot use the client").
 */
final class LessorMQMessengerBundle extends AbstractBundle
{
    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set('lessormq.messenger.transport_factory', LessorMQTransportFactory::class)
            ->args([
                null,
                service('logger')->nullOnInvalid(),
            ])
            ->tag('messenger.transport_factory')
            ->tag('monolog.logger', ['channel' => 'messenger']);
    }
}
