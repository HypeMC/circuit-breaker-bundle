<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle;

use Bizkit\CircuitBreakerBundle\DependencyInjection\CircuitBreakerHttpClientCompilerPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class BizkitCircuitBreakerBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        // Needs to run before Monolog's LoggerChannelPass.
        $container->addCompilerPass(new CircuitBreakerHttpClientCompilerPass(), priority: 10);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import(\dirname(__DIR__).'/config/definition.php');
    }

    /**
     * @param array{
     *     http_client?: array<string, bool|int|string|null>,
     *     scoped_http_clients?: array<string, array<string, bool|int|string|null>>,
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->import(\dirname(__DIR__).'/config/services.php');

        if (!class_exists(Command::class)) {
            $container->removeDefinition('bizkit_circuit_breaker.command.clear');
            $container->removeDefinition('bizkit_circuit_breaker.command.force');
            $container->removeDefinition('bizkit_circuit_breaker.command.status');
        }

        if (!$container->has('event_dispatcher')) {
            $container->removeDefinition('bizkit_circuit_breaker.event_dispatcher');
        }

        $container->setParameter('.bizkit_circuit_breaker.http_client', $config['http_client'] ?? []);
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', $config['scoped_http_clients'] ?? []);
    }
}
