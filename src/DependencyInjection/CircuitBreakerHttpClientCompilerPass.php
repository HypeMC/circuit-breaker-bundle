<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\DependencyInjection;

use Bizkit\CircuitBreakerBundle\HttpClient\CircuitBreakerHttpClient;
use GabrielAnhaia\PhpCircuitBreaker\CircuitBreaker;
use GabrielAnhaia\PhpCircuitBreaker\CircuitBreakerConfig;
use GabrielAnhaia\PhpCircuitBreaker\Storage\Psr6CacheStorage;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\TypedReference;

final class CircuitBreakerHttpClientCompilerPass implements CompilerPassInterface
{
    // After RetryableHttpClient (25), before TraceableHttpClient (100): record the final response after retries.
    private const DECORATION_PRIORITY = 30;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('.bizkit_circuit_breaker.http_client')) {
            return;
        }

        /**
         * @var array{
         *     storage: string|null,
         *     failure_threshold: int,
         *     success_threshold: int,
         *     time_window: int,
         *     open_timeout: int,
         *     half_open_timeout: int,
         *     exceptions_enabled: bool,
         *     failure_checker: string|null,
         * }|array{} $httpClientConfig
         */
        $httpClientConfig = $container->getParameter('.bizkit_circuit_breaker.http_client');

        $circuitBreakers = [];

        if (isset($httpClientConfig['storage'])) {
            $circuitBreakers['http_client'] = new TypedReference(
                self::decorateClient($container, 'http_client', $httpClientConfig),
                CircuitBreaker::class,
            );
        }

        /**
         * @var array<string, array{
         *     storage: string|null,
         *     failure_threshold: int,
         *     success_threshold: int,
         *     time_window: int,
         *     open_timeout: int,
         *     half_open_timeout: int,
         *     exceptions_enabled: bool,
         *     failure_checker: string|null,
         * }> $scopedClients
         */
        $scopedClients = $container->getParameter('.bizkit_circuit_breaker.scoped_http_clients');

        foreach ($scopedClients as $clientId => $clientConfig) {
            if (!isset($clientConfig['storage'])) {
                continue;
            }

            $circuitBreakers[$clientId] = new TypedReference(
                self::decorateClient($container, $clientId, $clientConfig),
                CircuitBreaker::class,
            );
        }

        $container->getDefinition('bizkit_circuit_breaker.locator')
            ->replaceArgument(0, $circuitBreakers);
    }

    /**
     * @param array{
     *     storage: string,
     *     failure_threshold: int,
     *     success_threshold: int,
     *     time_window: int,
     *     open_timeout: int,
     *     half_open_timeout: int,
     *     exceptions_enabled: bool,
     *     failure_checker: string|null,
     * } $config
     */
    private static function decorateClient(ContainerBuilder $container, string $clientId, array $config): string
    {
        if (!$container->has($clientId)) {
            throw new InvalidArgumentException(\sprintf(
                'Cannot enable circuit breaker for HTTP client "%s" because the service does not exist.',
                $clientId,
            ));
        }

        if (!$container->has($storageServiceId = $config['storage'])) {
            throw new InvalidArgumentException(\sprintf(
                'Cannot enable circuit breaker for HTTP client "%s" because the configured storage service "%s" does not exist.',
                $clientId,
                $storageServiceId,
            ));
        }

        if (!$container->has($failureCheckerId = $config['failure_checker'] ?? 'bizkit_circuit_breaker.failure_checker.default')) {
            throw new InvalidArgumentException(\sprintf(
                'Cannot enable circuit breaker for HTTP client "%s" because the configured failure checker service "%s" does not exist.',
                $clientId,
                $failureCheckerId,
            ));
        }

        if (!$container->hasDefinition($storageId = 'bizkit_circuit_breaker.storage.'.$storageServiceId)) {
            $container->register($storageId, Psr6CacheStorage::class)
                ->setArguments([new Reference($storageServiceId)]);
        }

        $idPrefix = 'bizkit_circuit_breaker.http_client'.('http_client' === $clientId ? '' : '.'.$clientId);

        $container->register($configId = $idPrefix.'.config', CircuitBreakerConfig::class)
            ->setArguments([
                $config['failure_threshold'],
                $config['success_threshold'],
                $config['time_window'],
                $config['open_timeout'],
                $config['half_open_timeout'],
                $config['exceptions_enabled'],
            ]);

        $container->register($circuitBreakerId = $idPrefix.'.circuit_breaker', CircuitBreaker::class)
            ->setArguments([
                new Reference($storageId),
                new Reference($configId),
                new Reference('bizkit_circuit_breaker.event_dispatcher', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
            ]);

        $container->register($idPrefix.'.decorator', CircuitBreakerHttpClient::class)
            ->setDecoratedService($clientId, null, self::DECORATION_PRIORITY)
            ->setArguments([
                new Reference('.inner'),
                new Reference($circuitBreakerId),
                new Reference($failureCheckerId),
                $clientId,
            ])
            ->addMethodCall('setLogger', [new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)])
            ->addTag('monolog.logger', ['channel' => 'bizkit_circuit_breaker'])
            ->addTag('kernel.reset', ['method' => 'reset', 'on_invalid' => 'ignore']);

        return $circuitBreakerId;
    }
}
