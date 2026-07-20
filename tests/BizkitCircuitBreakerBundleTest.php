<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests;

use Bizkit\CircuitBreakerBundle\BizkitCircuitBreakerBundle;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerClearCommand;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerForceCommand;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerStatusCommand;
use Bizkit\CircuitBreakerBundle\FailureChecker\DefaultFailureChecker;
use Bizkit\CircuitBreakerBundle\HttpClient\CircuitBreakerHttpClient;
use Bizkit\CircuitBreakerBundle\Tests\Fixtures\ClientErrorFailureChecker;
use Bizkit\CircuitBreakerBundle\Tests\Fixtures\TestCacheItemPool;
use GabrielAnhaia\PhpCircuitBreaker\Event\Psr14EventDispatcherBridge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\ClassExistsMock;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
                'time_window' => 40,
                'open_timeout' => 50,
                'half_open_timeout' => 15,
                'exceptions_enabled' => true,
                'failure_checker' => 'custom.failure_checker',
            ],
        ]);

        self::assertSame([
            'storage' => 'cache.app',
            'failure_threshold' => 6,
            'success_threshold' => 2,
            'time_window' => 40,
            'open_timeout' => 50,
            'half_open_timeout' => 15,
            'exceptions_enabled' => true,
            'failure_checker' => 'custom.failure_checker',
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
                    'exceptions_enabled' => true,
                ],
            ],
        ]);

        self::assertSame([], $container->getParameter('.bizkit_circuit_breaker.http_client'));
        self::assertSame([
            'client1' => [
                'storage' => 'cache.client1',
                'failure_threshold' => 2,
                'success_threshold' => 1,
                'time_window' => 20,
                'open_timeout' => 30,
                'half_open_timeout' => 20,
                'exceptions_enabled' => false,
                'failure_checker' => 'bizkit_circuit_breaker.failure_checker.default',
            ],
            'client2' => [
                'storage' => 'cache.client2',
                'success_threshold' => 3,
                'exceptions_enabled' => true,
                'failure_threshold' => 5,
                'time_window' => 20,
                'open_timeout' => 30,
                'half_open_timeout' => 20,
                'failure_checker' => 'bizkit_circuit_breaker.failure_checker.default',
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

        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.command.clear'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.command.force'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.command.status'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.event_dispatcher'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.failure_checker.default'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.locator'));

        self::assertSame(CircuitBreakerClearCommand::class, $container->getDefinition('bizkit_circuit_breaker.command.clear')->getClass());
        self::assertSame(CircuitBreakerForceCommand::class, $container->getDefinition('bizkit_circuit_breaker.command.force')->getClass());
        self::assertSame(CircuitBreakerStatusCommand::class, $container->getDefinition('bizkit_circuit_breaker.command.status')->getClass());
        self::assertSame(Psr14EventDispatcherBridge::class, $container->getDefinition('bizkit_circuit_breaker.event_dispatcher')->getClass());
        self::assertSame(DefaultFailureChecker::class, $container->getDefinition('bizkit_circuit_breaker.failure_checker.default')->getClass());
        self::assertSame(ServiceLocator::class, $container->getDefinition('bizkit_circuit_breaker.locator')->getClass());
    }

    public function testRemovesEventDispatcherBridgeWhenEventDispatcherIsUnavailable(): void
    {
        $container = self::buildContainer([], false);

        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.event_dispatcher'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.locator'));
    }

    public function testRemovesCommandsWhenConsoleIsUnavailable(): void
    {
        ClassExistsMock::withMockedClasses([
            Command::class => false,
        ]);

        $container = self::buildContainer();

        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.command.clear'));
        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.command.force'));
        self::assertFalse($container->hasDefinition('bizkit_circuit_breaker.command.status'));
        self::assertTrue($container->hasDefinition('bizkit_circuit_breaker.event_dispatcher'));
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

    public function testCompilesContainerWithCustomFailureChecker(): void
    {
        $container = self::buildContainer([
            'http_client' => [
                'storage' => 'cache.app',
                'failure_threshold' => 1,
                'failure_checker' => 'custom.failure_checker',
            ],
        ]);
        (new BizkitCircuitBreakerBundle())->build($container);

        $container->register('http_client', MockHttpClient::class)
            ->setArguments([[new MockResponse('not found', ['http_code' => 404])]])
            ->setPublic(true);
        $container->register('cache.app', TestCacheItemPool::class);
        $container->register('custom.failure_checker', ClientErrorFailureChecker::class);

        $container->compile();

        $httpClient = $container->get('http_client');
        self::assertInstanceOf(CircuitBreakerHttpClient::class, $httpClient);
        self::assertSame(404, $httpClient->request('GET', 'https://example.com')->getStatusCode());
        self::assertSame(503, $httpClient->request('GET', 'https://example.com')->getStatusCode());
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function buildContainer(array $config = [], bool $registerEventDispatcher = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', '');

        if ($registerEventDispatcher) {
            $container->register('event_dispatcher', EventDispatcher::class);
        }

        $extension = (new BizkitCircuitBreakerBundle())->getContainerExtension();
        $extension->load([$config], $container);

        return $container;
    }
}
