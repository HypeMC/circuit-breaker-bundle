<?php

declare(strict_types=1);

namespace Symfony\Component\Config\Definition\Configurator;

use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;

return static function (DefinitionConfigurator $definition): void {
    $stringOrNullNode = static function (string $name, string $info): ScalarNodeDefinition {
        $root = new NodeBuilder();

        return $root->scalarNode($name)
            ->info($info)
            ->defaultNull()
            ->validate()
                ->ifTrue(static fn (mixed $v): bool => null !== $v && !\is_string($v))
                ->thenInvalid(\sprintf('The %s option must be a string or null.', $name))
            ->end()
        ;
    };

    $stringNode = static function (string $name, string $info, string $defaultValue): ScalarNodeDefinition {
        $root = new NodeBuilder();

        return $root->scalarNode($name)
            ->info($info)
            ->defaultValue($defaultValue)
            ->validate()
                ->ifTrue(static fn (mixed $v): bool => !\is_string($v))
                ->thenInvalid(\sprintf('The %s option must be a string.', $name))
            ->end()
        ;
    };

    $rootNode = $definition->rootNode();

    $rootNode
        ->children()
            ->arrayNode('http_client')
                ->info('Circuit breaker configuration for the main Symfony HttpClient service.')
                ->children()
                    ->append($stringOrNullNode('storage', 'Service ID of the PSR-6 cache pool used to store circuit breaker state.'))
                    ->integerNode('failure_threshold')->defaultValue(5)->min(1)->end()
                    ->integerNode('success_threshold')->defaultValue(1)->min(1)->end()
                    ->integerNode('time_window')->defaultValue(20)->min(1)->end()
                    ->integerNode('open_timeout')->defaultValue(30)->min(1)->end()
                    ->integerNode('half_open_timeout')->defaultValue(20)->min(1)->end()
                    ->booleanNode('exceptions_enabled')->defaultFalse()->end()
                    ->append($stringNode(
                        'failure_checker',
                        'Service ID of the failure checker used to decide when a response should count as a circuit breaker failure.',
                        'bizkit_circuit_breaker.failure_checker.default',
                    ))
                    ->append($stringOrNullNode(
                        'service_name_resolver',
                        'Service ID of the resolver used to determine the circuit breaker service name for each request.',
                    ))
                ->end()
            ->end()
            ->arrayNode('scoped_http_clients')
                ->info('Circuit breaker configuration for scoped Symfony HttpClient services.')
                ->useAttributeAsKey('name')
                ->arrayPrototype()
                    ->children()
                        ->append($stringOrNullNode('storage', 'Service ID of the PSR-6 cache pool used to store circuit breaker state.'))
                        ->integerNode('failure_threshold')->defaultValue(5)->min(1)->end()
                        ->integerNode('success_threshold')->defaultValue(1)->min(1)->end()
                        ->integerNode('time_window')->defaultValue(20)->min(1)->end()
                        ->integerNode('open_timeout')->defaultValue(30)->min(1)->end()
                        ->integerNode('half_open_timeout')->defaultValue(20)->min(1)->end()
                        ->booleanNode('exceptions_enabled')->defaultFalse()->end()
                        ->append($stringNode(
                            'failure_checker',
                            'Service ID of the failure checker used to decide when a response should count as a circuit breaker failure.',
                            'bizkit_circuit_breaker.failure_checker.default',
                        ))
                        ->append($stringOrNullNode(
                            'service_name_resolver',
                            'Service ID of the resolver used to determine the circuit breaker service name for each request.',
                        ))
                    ->end()
                ->end()
            ->end()
        ->end()
    ;
};
