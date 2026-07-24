<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests;

use Bizkit\CircuitBreakerBundle\BizkitCircuitBreakerBundle;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerCloseCommand;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerOpenCommand;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerStatusCommand;
use Bizkit\CircuitBreakerBundle\Exception\OpenCircuitException;
use Bizkit\CircuitBreakerBundle\FailureChecker\DefaultFailureChecker;
use Bizkit\CircuitBreakerBundle\HttpClient\CircuitBreakerHttpClient;
use Bizkit\CircuitBreakerBundle\ServiceNameResolver\HostServiceNameResolver;
use Bizkit\CircuitBreakerBundle\Tests\Fixtures\ClientErrorFailureChecker;
use Bizkit\CircuitBreakerBundle\Tests\Fixtures\TestCacheItemPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\ClassExistsMock;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(BizkitCircuitBreakerBundle::class)]
final class BizkitCircuitBreakerBundleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ClassExistsMock::register(self::class);
    }

    protected function tearDown(): void
    {
        ClassExistsMock::withMockedClasses([]);
    }

    public function testLoadsHttpClientConfiguration(): void
    {
        $container = self::buildContainer([
            'http_client' => [
                'storage' => 'cache.app',
                'failure_threshold' => 6,
                'success_threshold' => 2,
                'failure_time_window' => 40,
                'open_timeout' => 50,
                'half_open_timeout' => 15,
                'half_open_max_attempts' => 2,
                'half_open_attempt_timeout' => 7,
                'failure_checker' => 'custom.failure_checker',
                'service_name_resolver' => 'bizkit_circuit_breaker.service_name_resolver.host',
            ],
        ]);

        self::assertSame([
            'storage' => 'cache.app',
            'failure_threshold' => 6,
            'success_threshold' => 2,
            'failure_time_window' => 40,
            'open_timeout' => 50,
            'half_open_timeout' => 15,
            'half_open_max_attempts' => 2,
            'half_open_attempt_timeout' => 7,
            'failure_checker' => 'custom.failure_checker',
            'service_name_resolver' => 'bizkit_circuit_breaker.service_name_resolver.host',
        ], $container->getParameter('.bizkit_circuit_breaker.http_client'));
        self::assertSame([], $container->getParameter('.bizkit_circuit_breaker.scoped_http_clients'));
    }

    public function testLoadsScopedHttpClientConfiguration(): void
    {
        $container = self::buildContainer([
            'scoped_http_clients' => [
                'client1' => [
                    'storage' => 'cache.client1',
                    'failure_threshold' => 2,
                ],
                'client2' => [
                    'storage' => 'cache.client2',
                    'success_threshold' => 3,
                ],
            ],
        ]);

        self::assertSame([], $container->getParameter('.bizkit_circuit_breaker.http_client'));
        self::assertSame([
            'client1' => [
                'storage' => 'cache.client1',
                'failure_threshold' => 2,
                'success_threshold' => 1,
                'failure_time_window' => 20,
                'open_timeout' => 30,
                'half_open_timeout' => 20,
                'half_open_max_attempts' => 1,
                'half_open_attempt_timeout' => 5,
                'failure_checker' => 'bizkit_circuit_breaker.failure_checker.default',
                'service_name_resolver' => null,
            ],
            'client2' => [
                'storage' => 'cache.client2',
                'success_threshold' => 3,
                'failure_threshold' => 5,
                'failure_time_window' => 20,
                'open_timeout' => 30,
                'half_open_timeout' => 20,
                'half_open_max_attempts' => 1,
                'half_open_attempt_timeout' => 5,
                'failure_checker' => 'bizkit_circuit_breaker.failure_checker.default',
                'service_name_resolver' => null,
            ],
        ], $container->getParameter('.bizkit_circuit_breaker.scoped_http_clients'));
    }

    public function testRejectsInvalidHttpClientConfiguration(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::buildContainer([
            'http_client' => [
                'failure_threshold' => 0,
            ],
        ]);
    }

    public function testRejectsInvalidScopedHttpClientConfiguration(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::buildContainer([
            'scoped_http_clients' => [
                'client1' => [
                    'success_threshold' => 0,
                ],
            ],
        ]);
    }

    public function testLoadsDefaultConfiguration(): void
    {
        $container = self::buildContainer();

        self::assertSame([], $container->getParameter('.bizkit_circuit_breaker.http_client'));
        self::assertSame([], $container->getParameter('.bizkit_circuit_breaker.scoped_http_clients'));

        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.command.close'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.command.open'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.command.status'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.failure_checker.default'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.service_name_resolver.host'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.locator'));

        self::assertSame(CircuitBreakerCloseCommand::class, $container->getDefinition('bizkit_circuit_breaker.command.close')->getClass());
        self::assertSame(CircuitBreakerOpenCommand::class, $container->getDefinition('bizkit_circuit_breaker.command.open')->getClass());
        self::assertSame(CircuitBreakerStatusCommand::class, $container->getDefinition('bizkit_circuit_breaker.command.status')->getClass());
        self::assertSame(DefaultFailureChecker::class, $container->getDefinition('bizkit_circuit_breaker.failure_checker.default')->getClass());
        self::assertSame(HostServiceNameResolver::class, $container->getDefinition('bizkit_circuit_breaker.service_name_resolver.host')->getClass());
        self::assertSame(ServiceLocator::class, $container->getDefinition('bizkit_circuit_breaker.locator')->getClass());
    }

    public function testRemovesCommandsWhenConsoleIsUnavailable(): void
    {
        ClassExistsMock::withMockedClasses([
            Command::class => false,
        ]);

        $container = self::buildContainer();

        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.command.close'));
        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.command.open'));
        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.command.status'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.locator'));
    }

    public function testCompilesContainerWithConfiguredHttpClients(): void
    {
        $container = self::buildContainer([
            'http_client' => [
                'storage' => 'cache.app',
            ],
            'scoped_http_clients' => [
                'client1' => [
                    'storage' => 'cache.app',
                ],
            ],
        ]);
        (new BizkitCircuitBreakerBundle())->build($container);

        $container->register('http_client', MockHttpClient::class)
            ->setArguments([[new MockResponse('main')]])
            ->setPublic(true);
        $container->register('client1', MockHttpClient::class)
            ->setArguments([[new MockResponse('scoped')]])
            ->setPublic(true);
        $container->register('cache.app', TestCacheItemPool::class);

        $container->compile();

        $httpClient = $container->get('http_client');
        self::assertInstanceOf(CircuitBreakerHttpClient::class, $httpClient);
        self::assertSame('main', $httpClient->request('GET', 'https://example.com')->getContent(false));

        $scopedClient = $container->get('client1');
        self::assertInstanceOf(CircuitBreakerHttpClient::class, $scopedClient);
        self::assertSame('scoped', $scopedClient->request('GET', 'https://example.com')->getContent(false));
    }

    public function testCompilesContainerWithCustomFailureCheckerAndServiceNameResolver(): void
    {
        $container = self::buildContainer([
            'http_client' => [
                'storage' => 'cache.app',
                'failure_threshold' => 1,
                'failure_checker' => 'custom.failure_checker',
                'service_name_resolver' => 'bizkit_circuit_breaker.service_name_resolver.host',
            ],
        ]);
        (new BizkitCircuitBreakerBundle())->build($container);

        $container->register('http_client', MockHttpClient::class)
            ->setArguments([[
                new MockResponse('not found', ['http_code' => 404]),
                new MockResponse('other not found', ['http_code' => 404]),
            ]])
            ->setPublic(true);
        $container->register('cache.app', TestCacheItemPool::class);
        $container->register('custom.failure_checker', ClientErrorFailureChecker::class);

        $container->compile();

        $httpClient = $container->get('http_client');
        self::assertInstanceOf(CircuitBreakerHttpClient::class, $httpClient);
        self::assertSame(404, $httpClient->request('GET', 'https://example.com')->getStatusCode());

        try {
            $httpClient->request('GET', 'https://example.com');
            self::fail('Expected an open circuit exception.');
        } catch (OpenCircuitException) {
        }

        self::assertSame(404, $httpClient->request('GET', 'https://another.example.com')->getStatusCode());

        try {
            $httpClient->request('GET', 'https://another.example.com');
            self::fail('Expected an open circuit exception.');
        } catch (OpenCircuitException) {
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function buildContainer(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', '');

        $extension = (new BizkitCircuitBreakerBundle())->getContainerExtension();
        $extension->load([$config], $container);

        return $container;
    }
}
