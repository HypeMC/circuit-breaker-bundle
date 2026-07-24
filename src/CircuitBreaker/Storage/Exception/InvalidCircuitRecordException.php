<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\Exception;

final class InvalidCircuitRecordException extends \UnexpectedValueException
{
    public static function invalidRecord(): self
    {
        return new self('Invalid circuit breaker record.');
    }

    public static function invalidState(): self
    {
        return new self('Invalid circuit breaker record state.');
    }
}
