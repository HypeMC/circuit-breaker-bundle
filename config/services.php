<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerClearCommand;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerForceCommand;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerStatusCommand;
use Bizkit\CircuitBreakerBundle\FailureChecker\DefaultFailureChecker;
use GabrielAnhaia\PhpCircuitBreaker\Event\Psr14EventDispatcherBridge;
use Symfony\Component\DependencyInjection\ServiceLocator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('bizkit_circuit_breaker.command.clear', CircuitBreakerClearCommand::class)
            ->args([service('bizkit_circuit_breaker.locator')])
            ->tag('console.command')

        ->set('bizkit_circuit_breaker.command.force', CircuitBreakerForceCommand::class)
            ->args([service('bizkit_circuit_breaker.locator')])
            ->tag('console.command')

        ->set('bizkit_circuit_breaker.command.status', CircuitBreakerStatusCommand::class)
            ->args([service('bizkit_circuit_breaker.locator')])
            ->tag('console.command')

        ->set('bizkit_circuit_breaker.event_dispatcher', Psr14EventDispatcherBridge::class)
            ->args([service('event_dispatcher')])

        ->set('bizkit_circuit_breaker.failure_checker.default', DefaultFailureChecker::class)

        ->set('bizkit_circuit_breaker.locator', ServiceLocator::class)
            ->args([abstract_arg('configured circuit breaker services')])
            ->tag('container.service_locator')
    ;
};
