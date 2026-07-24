<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Exception;

final class OpenCircuitException extends \RuntimeException
{
    public function __construct(
        public readonly string $serviceName,
        public readonly string $method,
        public readonly string $url,
    ) {
        parent::__construct(\sprintf('Circuit breaker is open for service name "%s".', $serviceName));
    }
}
