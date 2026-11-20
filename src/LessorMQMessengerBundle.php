<?php

declare(strict_types=1);

namespace LessorMQ\Messenger;

use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers the lessormq:// transport factory. Uses the app's PSR-18 client when there
 * is one (Symfony HttpClient with nyholm/psr7, say), else discovers one.
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
                service(ClientInterface::class)->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('messenger.transport_factory')
            ->tag('monolog.logger', ['channel' => 'messenger']);
    }
}
