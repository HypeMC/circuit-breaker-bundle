<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\HttpClient;

use Bizkit\CircuitBreakerBundle\FailureChecker\FailureCheckerInterface;
use GabrielAnhaia\PhpCircuitBreaker\CircuitBreaker;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
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
        private readonly string $serviceName,
    ) {
        $this->client = $client;
    }

    /**
     * @param array{
     *     extra?: array{
     *         circuit_breaker?: array{
     *             service_name?: ?non-empty-string,
     *             failure_checker?: callable(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool,
     *         },
     *         ...
     *     },
     *     ...
     * } $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $circuitBreakerOptions = $options['extra']['circuit_breaker'] ?? [];

        if (!\is_array($circuitBreakerOptions)) {
            throw new InvalidArgumentException('Option "extra.circuit_breaker" must be an array.');
        }

        $serviceName = $circuitBreakerOptions['service_name'] ?? $this->serviceName;
        if (!\is_string($serviceName) || '' === $serviceName) {
            throw new InvalidArgumentException('Option "extra.circuit_breaker.service_name" must be a non-empty string.');
        }

        $failureChecker = $circuitBreakerOptions['failure_checker'] ?? $this->failureChecker;
        if (!\is_callable($failureChecker)) {
            throw new InvalidArgumentException('Option "extra.circuit_breaker.failure_checker" must be callable.');
        }

        if (!$this->circuitBreaker->canPass($serviceName)) {
            $this->logger?->debug(
                'Circuit breaker blocked HTTP request.',
                self::createLogContext($method, $url, $serviceName),
            );

            return self::createOpenCircuitResponse($method, $url, $options, $serviceName);
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
     */
    private static function createOpenCircuitResponse(string $method, string $url, array $options, string $serviceName): ResponseInterface
    {
        $response = new JsonMockResponse(
            ['message' => 'Service unavailable.'],
            [
                'http_code' => 503,
                'circuit_breaker_open' => true,
                'circuit_breaker_service' => $serviceName,
            ],
        );

        return new AsyncResponse(new MockHttpClient($response), $method, $url, $options);
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
