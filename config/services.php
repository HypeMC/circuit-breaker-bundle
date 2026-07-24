<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerCloseCommand;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerOpenCommand;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerStatusCommand;
use Bizkit\CircuitBreakerBundle\FailureChecker\DefaultFailureChecker;
use Bizkit\CircuitBreakerBundle\ServiceNameResolver\HostServiceNameResolver;
use Symfony\Component\DependencyInjection\ServiceLocator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->private()

        ->set('bizkit_circuit_breaker.command.close', CircuitBreakerCloseCommand::class)
            ->args([service('bizkit_circuit_breaker.locator')])
            ->tag('console.command')

        ->set('bizkit_circuit_breaker.command.open', CircuitBreakerOpenCommand::class)
            ->args([service('bizkit_circuit_breaker.locator')])
            ->tag('console.command')

        ->set('bizkit_circuit_breaker.command.status', CircuitBreakerStatusCommand::class)
            ->args([service('bizkit_circuit_breaker.locator')])
            ->tag('console.command')

        ->set('bizkit_circuit_breaker.failure_checker.default', DefaultFailureChecker::class)

        ->set('bizkit_circuit_breaker.service_name_resolver.host', HostServiceNameResolver::class)

        ->set('bizkit_circuit_breaker.locator', ServiceLocator::class)
            ->args([abstract_arg('configured circuit breaker services')])
            ->tag('container.service_locator')
    ;
};
