<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\DependencyInjection;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitBreaker;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Settings;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\Psr6CacheStorage;
use Bizkit\CircuitBreakerBundle\DependencyInjection\CircuitBreakerHttpClientCompilerPass;
use Bizkit\CircuitBreakerBundle\FailureChecker\DefaultFailureChecker;
use Bizkit\CircuitBreakerBundle\HttpClient\CircuitBreakerHttpClient;
use Bizkit\CircuitBreakerBundle\ServiceNameResolver\HostServiceNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(CircuitBreakerHttpClientCompilerPass::class)]
final class CircuitBreakerHttpClientCompilerPassTest extends TestCase
{
    public function testDoesNothingWhenBundleParametersAreMissing(): void
    {
        $container = new ContainerBuilder();

        (new CircuitBreakerHttpClientCompilerPass())->process($container);

        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.http_client.decorator'));
    }

    public function testDecoratesMainHttpClient(): void
    {
        $container = self::createContainer();
        $container->setParameter('.bizkit_circuit_breaker.http_client', self::config(['storage' => 'cache.app']));
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', []);

        (new CircuitBreakerHttpClientCompilerPass())->process($container);

        self::assertTrue($container->hasDefinition($storageId = 'bizkit_circuit_breaker.storage.cache.app'));
        self::assertTrue($container->hasDefinition($configId = 'bizkit_circuit_breaker.http_client.config'));
        self::assertTrue($container->hasDefinition($circuitBreakerId = 'bizkit_circuit_breaker.http_client.circuit_breaker'));
        self::assertTrue($container->hasDefinition($decoratorId = 'bizkit_circuit_breaker.http_client.decorator'));

        $storage = $container->getDefinition($storageId);
        self::assertSame(Psr6CacheStorage::class, $storage->getClass());
        self::assertSame('cache.app', (string) $storage->getArgument(0));

        $config = $container->getDefinition($configId);
        self::assertSame(Settings::class, $config->getClass());

        $circuitBreaker = $container->getDefinition($circuitBreakerId);
        self::assertSame(CircuitBreaker::class, $circuitBreaker->getClass());
        self::assertSame($storageId, (string) $circuitBreaker->getArgument(0));
        self::assertSame('bizkit_circuit_breaker.http_client.config', (string) $circuitBreaker->getArgument(1));

        $decorator = $container->getDefinition($decoratorId);
        self::assertSame(CircuitBreakerHttpClient::class, $decorator->getClass());
        self::assertSame('bizkit_circuit_breaker.http_client.circuit_breaker', (string) $decorator->getArgument(1));
        self::assertSame('bizkit_circuit_breaker.failure_checker.default', (string) $decorator->getArgument(2));
        self::assertSame('http_client', $decorator->getArgument(3));
        self::assertCount(4, $decorator->getArguments());
        self::assertSame(['http_client', null, 30], $decorator->getDecoratedService());
        self::assertSame('setLogger', $decorator->getMethodCalls()[0][0]);
        self::assertSame('logger', (string) $loggerArg = $decorator->getMethodCalls()[0][1][0]);
        self::assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $loggerArg->getInvalidBehavior());
        self::assertSame([['channel' => 'bizkit_circuit_breaker']], $decorator->getTag('monolog.logger'));

        self::assertCommandServiceLocatorContains($container, ['http_client']);
    }

    public function testDecoratesScopedHttpClient(): void
    {
        $container = self::createContainer();
        $container->register('client1', HttpClientInterface::class);
        $container->setParameter('.bizkit_circuit_breaker.http_client', []);
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', [
            'client1' => self::config(['storage' => 'cache.app', 'failure_threshold' => 2, 'half_open_timeout' => 17]),
        ]);

        (new CircuitBreakerHttpClientCompilerPass())->process($container);

        self::assertTrue($container->hasDefinition($storageId = 'bizkit_circuit_breaker.storage.cache.app'));
        self::assertTrue($container->hasDefinition($configId = 'bizkit_circuit_breaker.http_client.client1.config'));
        self::assertTrue($container->hasDefinition($decoratorId = 'bizkit_circuit_breaker.http_client.client1.decorator'));

        $storage = $container->getDefinition($storageId);
        self::assertSame(Psr6CacheStorage::class, $storage->getClass());
        self::assertSame('cache.app', (string) $storage->getArgument(0));

        $config = $container->getDefinition($configId);
        self::assertSame([2, 1, 20, 30, 17, 1, 5], $config->getArguments());

        $decorator = $container->getDefinition($decoratorId);
        self::assertSame('bizkit_circuit_breaker.failure_checker.default', (string) $decorator->getArgument(2));
        self::assertSame('client1', $decorator->getArgument(3));
        self::assertCount(4, $decorator->getArguments());
        self::assertSame(['client1', null, 30], $decorator->getDecoratedService());

        self::assertCommandServiceLocatorContains($container, ['client1']);
    }

    public function testUsesCustomFailureCheckerForMainHttpClient(): void
    {
        $container = self::createContainer();
        $container->register('custom.failure_checker', DefaultFailureChecker::class);
        $container->setParameter('.bizkit_circuit_breaker.http_client', self::config([
            'storage' => 'cache.app',
            'failure_checker' => 'custom.failure_checker',
        ]));
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', []);

        (new CircuitBreakerHttpClientCompilerPass())->process($container);

        $decorator = $container->getDefinition('bizkit_circuit_breaker.http_client.decorator');
        self::assertSame('custom.failure_checker', (string) $decorator->getArgument(2));
    }

    public function testUsesCustomFailureCheckerForScopedHttpClient(): void
    {
        $container = self::createContainer();
        $container->register('client1', HttpClientInterface::class);
        $container->register('custom.failure_checker', DefaultFailureChecker::class);
        $container->setParameter('.bizkit_circuit_breaker.http_client', []);
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', [
            'client1' => self::config([
                'storage' => 'cache.app',
                'failure_checker' => 'custom.failure_checker',
            ]),
        ]);

        (new CircuitBreakerHttpClientCompilerPass())->process($container);

        $decorator = $container->getDefinition('bizkit_circuit_breaker.http_client.client1.decorator');
        self::assertSame('custom.failure_checker', (string) $decorator->getArgument(2));
    }

    public function testUsesCustomServiceNameResolverForMainHttpClient(): void
    {
        $container = self::createContainer();
        $container->register('custom.service_name_resolver', HostServiceNameResolver::class);
        $container->setParameter('.bizkit_circuit_breaker.http_client', self::config([
            'storage' => 'cache.app',
            'service_name_resolver' => 'custom.service_name_resolver',
        ]));
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', []);

        (new CircuitBreakerHttpClientCompilerPass())->process($container);

        $decorator = $container->getDefinition('bizkit_circuit_breaker.http_client.decorator');
        self::assertSame('custom.service_name_resolver', (string) $decorator->getArgument(4));
    }

    public function testUsesCustomServiceNameResolverForScopedHttpClient(): void
    {
        $container = self::createContainer();
        $container->register('client1', HttpClientInterface::class);
        $container->register('custom.service_name_resolver', HostServiceNameResolver::class);
        $container->setParameter('.bizkit_circuit_breaker.http_client', []);
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', [
            'client1' => self::config([
                'storage' => 'cache.app',
                'service_name_resolver' => 'custom.service_name_resolver',
            ]),
        ]);

        (new CircuitBreakerHttpClientCompilerPass())->process($container);

        $decorator = $container->getDefinition('bizkit_circuit_breaker.http_client.client1.decorator');
        self::assertSame('custom.service_name_resolver', (string) $decorator->getArgument(4));
    }

    public function testReusesStorageWrapperForClientsWithSameStorageService(): void
    {
        $container = self::createContainer();
        $container->register('client1', HttpClientInterface::class);
        $container->setParameter('.bizkit_circuit_breaker.http_client', self::config(['storage' => 'cache.app']));
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', [
            'client1' => self::config(['storage' => 'cache.app']),
        ]);

        (new CircuitBreakerHttpClientCompilerPass())->process($container);

        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.storage.cache.app'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.http_client.decorator'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.http_client.client1.decorator'));

        self::assertCount(1, array_filter(
            $container->getDefinitions(),
            static fn (Definition $definition): bool => Psr6CacheStorage::class === $definition->getClass(),
        ));

        self::assertCommandServiceLocatorContains($container, ['http_client', 'client1']);
    }

    public function testFailsForUnknownScopedHttpClient(): void
    {
        $container = self::createContainer();
        $container->setParameter('.bizkit_circuit_breaker.http_client', []);
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', [
            'missing_client' => self::config(['storage' => 'cache.app']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_client');

        (new CircuitBreakerHttpClientCompilerPass())->process($container);
    }

    public function testFailsForUnknownStorageService(): void
    {
        $container = self::createContainer();
        $container->setParameter('.bizkit_circuit_breaker.http_client', self::config([
            'storage' => 'missing.storage',
        ]));
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing.storage');

        (new CircuitBreakerHttpClientCompilerPass())->process($container);
    }

    public function testFailsForUnknownFailureCheckerService(): void
    {
        $container = self::createContainer();
        $container->setParameter('.bizkit_circuit_breaker.http_client', self::config([
            'storage' => 'cache.app',
            'failure_checker' => 'missing.failure_checker',
        ]));
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing.failure_checker');

        (new CircuitBreakerHttpClientCompilerPass())->process($container);
    }

    public function testFailsForUnknownServiceNameResolverService(): void
    {
        $container = self::createContainer();
        $container->setParameter('.bizkit_circuit_breaker.http_client', self::config([
            'storage' => 'cache.app',
            'service_name_resolver' => 'missing.service_name_resolver',
        ]));
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing.service_name_resolver');

        (new CircuitBreakerHttpClientCompilerPass())->process($container);
    }

    public function testSkipsClientsWithNullStorage(): void
    {
        $container = self::createContainer();
        $container->register('client1', HttpClientInterface::class);
        $container->setParameter('.bizkit_circuit_breaker.http_client', self::config(['storage' => null]));
        $container->setParameter('.bizkit_circuit_breaker.scoped_http_clients', [
            'client1' => self::config(['storage' => null]),
        ]);

        (new CircuitBreakerHttpClientCompilerPass())->process($container);

        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.http_client.decorator'));
        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.http_client.client1.decorator'));

        self::assertCommandServiceLocatorContains($container, []);
    }

    /**
     * @param list<string> $serviceNames
     */
    private static function assertCommandServiceLocatorContains(ContainerBuilder $container, array $serviceNames): void
    {
        $locator = $container->getDefinition('bizkit_circuit_breaker.locator');

        self::assertSame($serviceNames, array_keys($locator->getArgument(0)));
    }

    /**
     * @param array<string, mixed> $configOverrides
     *
     * @return array<string, mixed>
     */
    private static function config(array $configOverrides = []): array
    {
        return array_replace([
            'storage' => null,
            'failure_threshold' => 5,
            'success_threshold' => 1,
            'failure_time_window' => 20,
            'open_timeout' => 30,
            'half_open_timeout' => 20,
            'half_open_max_attempts' => 1,
            'half_open_attempt_timeout' => 5,
            'failure_checker' => 'bizkit_circuit_breaker.failure_checker.default',
            'service_name_resolver' => null,
        ], $configOverrides);
    }

    private static function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('http_client', HttpClientInterface::class);
        $container->register('cache.app', CacheItemPoolInterface::class);
        $container->register('bizkit_circuit_breaker.failure_checker.default', DefaultFailureChecker::class);
        $container->register('bizkit_circuit_breaker.locator', ServiceLocator::class)->setArguments([new AbstractArgument()]);

        return $container;
    }
}
