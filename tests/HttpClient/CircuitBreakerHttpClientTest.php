<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\HttpClient;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitBreaker;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Settings;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\InMemoryStorage;
use Bizkit\CircuitBreakerBundle\Exception\OpenCircuitException;
use Bizkit\CircuitBreakerBundle\FailureChecker\DefaultFailureChecker;
use Bizkit\CircuitBreakerBundle\FailureChecker\FailureCheckerInterface;
use Bizkit\CircuitBreakerBundle\HttpClient\CircuitBreakerHttpClient;
use Bizkit\CircuitBreakerBundle\ServiceNameResolver\HostServiceNameResolver;
use Bizkit\CircuitBreakerBundle\ServiceNameResolver\ServiceNameResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;

#[CoversClass(CircuitBreakerHttpClient::class)]
#[CoversClass(OpenCircuitException::class)]
final class CircuitBreakerHttpClientTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    #[TestWith([[], 'api'], 'default service name')]
    #[TestWith([['extra' => ['circuit_breaker' => ['service_name' => 'payments']]], 'api:payments'], 'overridden service name')]
    #[TestWith([['extra' => ['circuit_breaker' => ['service_name' => 'strlen']]], 'api:strlen'], 'callable string service name')]
    public function testRecordsSuccessForSuccessfulResponseAfterBodyCompletes(array $options, string $serviceName): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $circuitBreaker->forceState($serviceName, CircuitState::HalfOpen);
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('ok', ['http_code' => 200])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );

        $response = $client->request('GET', 'https://example.com', $options);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState($serviceName));
        self::assertSame('ok', $response->getContent(false));
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState($serviceName));
    }

    public function testRecordsSuccessForClientErrorResponseAfterBodyCompletes(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $circuitBreaker->forceState('api', CircuitState::HalfOpen);
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('not found', ['http_code' => 404])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );

        $response = $client->request('GET', 'https://example.com');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));
        self::assertSame('not found', $response->getContent(false));
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    public function testBlocksAdditionalHalfOpenAttemptsWhenAttemptLimitIsReached(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            halfOpenMaxConcurrentAttempts: 1,
        ));
        $circuitBreaker->forceState('api', CircuitState::HalfOpen);
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('ok', ['http_code' => 200])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );

        $response = $client->request('GET', 'https://example.com');

        try {
            $client->request('GET', 'https://example.com');
            self::fail('Expected an open circuit exception.');
        } catch (OpenCircuitException) {
        }

        self::assertSame('ok', $response->getContent(false));
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[TestWith([[], 'api'], 'default service name')]
    #[TestWith([['extra' => ['circuit_breaker' => ['service_name' => 'payments']]], 'api:payments'], 'overridden service name')]
    public function testRecordsFailureForServerErrorResponse(array $options, string $serviceName): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );

        self::assertSame(500, $client->request('GET', 'https://example.com', $options)->getStatusCode());
        self::assertSame(CircuitState::Open, $circuitBreaker->getState($serviceName));
    }

    public function testInjectedFailureCheckerCanRecordClientErrorAsFailure(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $failureChecker = new class implements FailureCheckerInterface {
            public function __invoke(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool
            {
                return $chunk->isFirst() && $context->getStatusCode() >= 400;
            }
        };
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 404])),
            $circuitBreaker,
            $failureChecker,
            'api',
        );

        self::assertSame(404, $client->request('GET', 'https://example.com')->getStatusCode());
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
    }

    public function testInjectedFailureCheckerCanIgnoreServerError(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $circuitBreaker->forceState('api', CircuitState::HalfOpen);
        $failureChecker = new class implements FailureCheckerInterface {
            public function __invoke(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool
            {
                return false;
            }
        };
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('server error', ['http_code' => 500])),
            $circuitBreaker,
            $failureChecker,
            'api',
        );

        $response = $client->request('GET', 'https://example.com');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));
        self::assertSame('server error', $response->getContent(false));
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    public function testRequestFailureCheckerOverridesInjectedFailureChecker(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $failureChecker = new class implements FailureCheckerInterface {
            public function __invoke(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool
            {
                return false;
            }
        };
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 404])),
            $circuitBreaker,
            $failureChecker,
            'api',
        );

        $response = $client->request('GET', 'https://example.com', [
            'extra' => [
                'circuit_breaker' => [
                    'failure_checker' => static fn (ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool => $chunk->isFirst() && 404 === $context->getStatusCode(),
                ],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
    }

    public function testRequestFailureCheckerOverrideReceivesOverriddenServiceName(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $failureChecker = new class implements FailureCheckerInterface {
            public function __invoke(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool
            {
                return false;
            }
        };
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 404])),
            $circuitBreaker,
            $failureChecker,
            'api',
        );

        $response = $client->request('GET', 'https://example.com', [
            'extra' => [
                'circuit_breaker' => [
                    'service_name' => 'payments',
                    'failure_checker' => static fn (ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool => 'api:payments' === $serviceName
                        && $chunk->isFirst()
                        && 404 === $context->getStatusCode(),
                ],
            ],
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api:payments'));
    }

    public function testInjectedServiceNameResolverCanResolveServiceName(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
            new HostServiceNameResolver(),
        );

        self::assertSame(500, $client->request('GET', 'https://example.com/path')->getStatusCode());
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api:example.com'));
    }

    public function testServiceNameResolverCanFallBackToDefaultServiceName(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
            new HostServiceNameResolver(),
        );

        self::assertSame(500, $client->request('GET', '/path')->getStatusCode());
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
    }

    public function testRequestServiceNameCallableOverrideWinsOverInjectedResolver(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
            new HostServiceNameResolver(),
        );

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $calls */
        $calls = [];
        /** @var array<string, mixed> $options */
        $options = [
            'extra' => [
                'circuit_breaker' => [
                    'service_name' => static function (string $method, string $url, array $options) use (&$calls): string {
                        $calls[] = [$method, $url, $options];

                        return 'payments';
                    },
                ],
            ],
        ];

        $response = $client->request('GET', 'https://example.com/path', $options);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([['GET', 'https://example.com/path', $options]], $calls);
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api:example.com'));
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api:payments'));
    }

    public function testRequestServiceNameCallableCanFallBackToInjectedResolver(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
            new HostServiceNameResolver(),
        );

        $response = $client->request('GET', 'https://example.com/path', [
            'extra' => [
                'circuit_breaker' => [
                    'service_name' => static fn (string $method, string $url, array $options): ?string => null,
                ],
            ],
        ]);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api:example.com'));
    }

    public function testRecordsFailureForServerErrorResponseWhenStreaming(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('error', ['http_code' => 500])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );
        $response = $client->request('GET', 'https://example.com');

        $this->expectException(ServerException::class);

        try {
            foreach ($client->stream($response) as $chunk) {
                $chunk->getContent();
            }
        } finally {
            self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
        }
    }

    #[TestWith([new MockResponse('', ['error' => 'host unreachable'])], 'before headers')]
    #[TestWith([new MockResponse([new TransportException('Network failure')])], 'while streaming body')]
    public function testRecordsFailureForTransportException(MockResponse $response): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient($response),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );

        $this->expectException(TransportException::class);

        try {
            $client->request('GET', 'https://example.com')->getContent(false);
        } finally {
            self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
        }
    }

    public function testRecordsFailureOnlyOnceWhenServerErrorResponseAlsoFailsWhileStreamingBody(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 2));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse([new TransportException('Network failure')], ['http_code' => 500])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );

        $this->expectException(TransportException::class);

        try {
            $client->request('GET', 'https://example.com')->getContent(false);
        } finally {
            self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
        }
    }

    public function testLogsRecordedSuccess(): void
    {
        $records = [];
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('ok', ['http_code' => 200])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );
        $client->setLogger(self::createLogger($records));

        self::assertSame('ok', $client->request('GET', 'https://example.com')->getContent(false));

        self::assertSame([
            [
                'level' => 'debug',
                'message' => 'Circuit breaker recorded HTTP request success.',
                'context' => [
                    'service_name' => 'api',
                    'method' => 'GET',
                    'url' => 'https://example.com',
                    'status_code' => 200,
                ],
            ],
        ], $records);
    }

    public function testLogsRecordedFailure(): void
    {
        $records = [];
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );
        $client->setLogger(self::createLogger($records));

        self::assertSame(500, $client->request('GET', 'https://example.com')->getStatusCode());

        self::assertSame([
            [
                'level' => 'debug',
                'message' => 'Circuit breaker recorded HTTP request failure.',
                'context' => [
                    'service_name' => 'api',
                    'method' => 'GET',
                    'url' => 'https://example.com',
                    'status_code' => 500,
                ],
            ],
        ], $records);
    }

    public function testLogsRecordedTransportFailure(): void
    {
        $records = [];
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('', ['error' => 'host unreachable'])),
            $circuitBreaker,
            new DefaultFailureChecker(),
            'api',
        );
        $client->setLogger(self::createLogger($records));

        $this->expectException(TransportException::class);

        try {
            $client->request('GET', 'https://example.com')->getContent();
        } finally {
            self::assertSame([
                [
                    'level' => 'debug',
                    'message' => 'Circuit breaker recorded HTTP request failure.',
                    'context' => [
                        'service_name' => 'api',
                        'method' => 'GET',
                        'url' => 'https://example.com',
                        'status_code' => 200,
                        'error' => 'host unreachable',
                    ],
                ],
            ], $records);
        }
    }

    public function testLogsBlockedRequestWhenCircuitIsOpen(): void
    {
        $records = [];
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings());
        $circuitBreaker->forceState('api', CircuitState::Open);

        $client = new CircuitBreakerHttpClient(new MockHttpClient(), $circuitBreaker, new DefaultFailureChecker(), 'api');
        $client->setLogger(self::createLogger($records));

        try {
            $client->request('GET', 'https://example.com');
            self::fail('Expected an open circuit exception.');
        } catch (OpenCircuitException) {
        }

        self::assertSame([
            [
                'level' => 'debug',
                'message' => 'Circuit breaker blocked HTTP request.',
                'context' => [
                    'service_name' => 'api',
                    'method' => 'GET',
                    'url' => 'https://example.com',
                ],
            ],
        ], $records);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[TestWith([[], 'api'], 'default service name')]
    #[TestWith([['extra' => ['circuit_breaker' => ['service_name' => 'payments']]], 'api:payments'], 'overridden service name')]
    public function testThrowsWithoutCallingDecoratedClientWhenCircuitIsOpen(array $options, string $serviceName): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings());
        $circuitBreaker->forceState($serviceName, CircuitState::Open);
        $innerClient = new MockHttpClient();

        $client = new CircuitBreakerHttpClient($innerClient, $circuitBreaker, new DefaultFailureChecker(), 'api');

        try {
            $client->request('GET', 'https://example.com', $options);
            self::fail('Expected an open circuit exception.');
        } catch (OpenCircuitException $exception) {
            self::assertSame($serviceName, $exception->serviceName);
            self::assertSame('GET', $exception->method);
            self::assertSame('https://example.com', $exception->url);
            self::assertNull($exception->getPrevious());
        }

        self::assertSame(0, $innerClient->getRequestsCount());
    }

    public function testThrowsWhenCircuitIsOpen(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings());
        $circuitBreaker->forceState('api', CircuitState::Open);

        $client = new CircuitBreakerHttpClient(new MockHttpClient(), $circuitBreaker, new DefaultFailureChecker(), 'api');

        try {
            $client->request('POST', 'https://example.com');
            self::fail('Expected an open circuit exception.');
        } catch (OpenCircuitException $exception) {
            self::assertSame('api', $exception->serviceName);
            self::assertSame('POST', $exception->method);
            self::assertSame('https://example.com', $exception->url);
            self::assertNull($exception->getPrevious());
        }
    }

    public function testResetResetsDecoratedClientWithoutClearingCircuitStorage(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(failureThreshold: 1));
        $innerClient = new MockHttpClient(new MockResponse());

        $client = new CircuitBreakerHttpClient($innerClient, $circuitBreaker, new DefaultFailureChecker(), 'api');
        $client->request('GET', 'https://example.com')->getStatusCode();
        self::assertSame(1, $innerClient->getRequestsCount());

        $circuitBreaker->forceState('api', CircuitState::Open);
        $client->reset();

        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
        self::assertSame(0, $innerClient->getRequestsCount());
    }

    public function testRejectsServiceNameResolverReturningEmptyString(): void
    {
        $storage = new InMemoryStorage();
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('ok')),
            new CircuitBreaker($storage),
            new DefaultFailureChecker(),
            'api',
            new class implements ServiceNameResolverInterface {
                /** @param array<string, mixed> $options */
                public function resolve(string $method, string $url, array $options): ?string
                {
                    return '';
                }
            },
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The configured circuit breaker service name resolver must return null or a non-empty string.');

        $client->request('GET', 'https://example.com');
    }

    public function testRejectsInvalidCircuitBreakerOptions(): void
    {
        $storage = new InMemoryStorage();
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('ok')),
            new CircuitBreaker($storage),
            new DefaultFailureChecker(),
            'api',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option "extra.circuit_breaker" must be an array.');

        $client->request('GET', 'https://example.com', [
            'extra' => [
                'circuit_breaker' => 'invalid',
            ],
        ]);
    }

    #[TestWith([''], 'empty service name')]
    #[TestWith([123], 'non-string service name')]
    public function testRejectsServiceNameCallableReturningInvalidValue(mixed $returnValue): void
    {
        $storage = new InMemoryStorage();
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('ok')),
            new CircuitBreaker($storage),
            new DefaultFailureChecker(),
            'api',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option "extra.circuit_breaker.service_name" must be a non-empty string, or a callable returning null or a non-empty string.');

        $client->request('GET', 'https://example.com', [
            'extra' => [
                'circuit_breaker' => [
                    'service_name' => static fn (string $method, string $url, array $options): mixed => $returnValue,
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[TestWith([['extra' => ['circuit_breaker' => ['service_name' => '']]]], 'empty service name')]
    #[TestWith([['extra' => ['circuit_breaker' => ['service_name' => 123]]]], 'non-string service name')]
    public function testRejectsInvalidOverriddenServiceName(array $options): void
    {
        $storage = new InMemoryStorage();
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('ok')),
            new CircuitBreaker($storage),
            new DefaultFailureChecker(),
            'api',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option "extra.circuit_breaker.service_name" must be a non-empty string, or a callable returning null or a non-empty string.');

        $client->request('GET', 'https://example.com', $options);
    }

    public function testRejectsInvalidRequestFailureChecker(): void
    {
        $storage = new InMemoryStorage();
        $client = new CircuitBreakerHttpClient(
            new MockHttpClient(new MockResponse('ok')),
            new CircuitBreaker($storage),
            new DefaultFailureChecker(),
            'api',
        );

        /** @var array<string, mixed> $options */
        $options = [
            'extra' => [
                'circuit_breaker' => [
                    'failure_checker' => 'not_a_function',
                ],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option "extra.circuit_breaker.failure_checker" must be callable.');

        $client->request('GET', 'https://example.com', $options);
    }

    /**
     * @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records
     */
    private static function createLogger(array &$records): LoggerInterface
    {
        return new class($records) extends AbstractLogger {
            /**
             * @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records
             */
            public function __construct(
                private array &$records,
            ) {
            }

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
