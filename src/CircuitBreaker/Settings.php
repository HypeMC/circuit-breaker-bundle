<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker;

final class Settings
{
    public function __construct(
        /** Number of failures inside the failure time window before the circuit opens. */
        public readonly int $failureThreshold = 5,

        /** Number of successful half-open attempts required before the circuit closes. */
        public readonly int $successThreshold = 1,

        /** Number of seconds during which closed-state failures are counted. */
        public readonly int $failureTimeWindow = 20,

        /** Number of seconds an open circuit stays open before moving to half-open. */
        public readonly int $openTimeout = 30,

        /** Number of seconds a half-open circuit waits for attempt results before closing. */
        public readonly int $halfOpenTimeout = 20,

        /** Maximum number of half-open attempts allowed at the same time. */
        public readonly int $halfOpenMaxAttempts = 1,
    ) {
    }
}
