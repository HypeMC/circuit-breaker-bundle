<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\HttpClient;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitBreaker;
use Bizkit\CircuitBreakerBundle\Exception\OpenCircuitException;
use Bizkit\CircuitBreakerBundle\FailureChecker\FailureCheckerInterface;
use Bizkit\CircuitBreakerBundle\ServiceNameResolver\ServiceNameResolverInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

final class CircuitBreakerHttpClient implements HttpClientInterface, ResetInterface, LoggerAwareInterface
{
    use AsyncDecoratorTrait;
    use LoggerAwareTrait;

    public function __construct(
        HttpClientInterface $client,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly FailureCheckerInterface $failureChecker,
        private readonly string $defaultServiceName,
        private readonly ?ServiceNameResolverInterface $serviceNameResolver = null,
    ) {
        $this->client = $client;
    }

    /**
     * @param array{
     *     extra?: array{
     *         circuit_breaker?: array{
     *             service_name?: non-empty-string|callable(string $method, string $url, array<string, mixed> $options): ?non-empty-string|null,
     *             failure_checker?: callable(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool|null,
     *         },
     *         ...
     *     },
     *     ...
     * } $options
     *
     * @throws OpenCircuitException
     * @throws TransportExceptionInterface
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (!\is_array($circuitBreakerOptions = $options['extra']['circuit_breaker'] ?? [])) {
            throw new InvalidArgumentException('Option "extra.circuit_breaker" must be an array.');
        }

        if (!\is_callable($failureChecker = $circuitBreakerOptions['failure_checker'] ?? $this->failureChecker)) {
            throw new InvalidArgumentException('Option "extra.circuit_breaker.failure_checker" must be callable.');
        }

        $serviceName = $this->resolveServiceName($method, $url, $options, $circuitBreakerOptions);

        if (!$this->circuitBreaker->tryAcquireAttempt($serviceName)) {
            $this->logger?->debug(
                'Circuit breaker blocked HTTP request.',
                self::createLogContext($method, $url, $serviceName),
            );

            throw new OpenCircuitException($serviceName, $method, $url);
        }

        $recorded = false;

        return new AsyncResponse(
            $this->client,
            $method,
            $url,
            $options,
            function (ChunkInterface $chunk, AsyncContext $context) use (&$recorded, $failureChecker, $serviceName, $method, $url): \Generator {
                if (!$recorded) {
                    if ($failureChecker($chunk, $context, $serviceName)) {
                        $this->circuitBreaker->recordFailure($serviceName);
                        $this->logger?->debug(
                            'Circuit breaker recorded HTTP request failure.',
                            self::createLogContext($method, $url, $serviceName, $chunk, $context),
                        );
                        $recorded = true;
                    } elseif ($chunk->isLast()) {
                        $this->circuitBreaker->recordSuccess($serviceName);
                        $this->logger?->debug(
                            'Circuit breaker recorded HTTP request success.',
                            self::createLogContext($method, $url, $serviceName, $chunk, $context),
                        );
                        $recorded = true;
                    }
                }

                yield $chunk;
            },
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{
     *     service_name?: non-empty-string|callable(string $method, string $url, array<string, mixed> $options): ?non-empty-string|null,
     *     failure_checker?: callable(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool|null,
     * } $circuitBreakerOptions
     */
    private function resolveServiceName(string $method, string $url, array $options, array $circuitBreakerOptions): string
    {
        $serviceName = $circuitBreakerOptions['service_name'] ?? null;

        if (null !== $serviceName && !\is_string($serviceName) && \is_callable($serviceName)) {
            $serviceName = $serviceName($method, $url, $options);
        }

        if (null !== $serviceName) {
            if (!\is_string($serviceName) || '' === $serviceName) {
                throw new InvalidArgumentException('Option "extra.circuit_breaker.service_name" must be a non-empty string, or a callable returning null or a non-empty string.');
            }

            return $this->defaultServiceName.':'.$serviceName;
        }

        if (null === $serviceName = $this->serviceNameResolver?->resolve($method, $url, $options)) {
            return $this->defaultServiceName;
        }

        if ('' === $serviceName) {
            throw new InvalidArgumentException('The configured circuit breaker service name resolver must return null or a non-empty string.');
        }

        return $this->defaultServiceName.':'.$serviceName;
    }

    /**
     * @return array<string, int|string>
     */
    private static function createLogContext(
        string $method,
        string $url,
        string $serviceName,
        ?ChunkInterface $chunk = null,
        ?AsyncContext $context = null,
    ): array {
        $logContext = [
            'service_name' => $serviceName,
            'method' => $method,
            'url' => $url,
        ];

        if (null !== $context && 0 !== $statusCode = $context->getStatusCode()) {
            $logContext['status_code'] = $statusCode;
        }

        if (null !== $chunk && null !== $error = $chunk->getError()) {
            $logContext['error'] = $error;
        }

        return $logContext;
    }
}
