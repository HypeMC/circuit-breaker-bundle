<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\Exception\InvalidCircuitRecordException;

final class CircuitRecord
{
    public function __construct(
        public readonly CircuitState $state,
        public readonly int $failureCount = 0,
        public readonly int $successCount = 0,
        public readonly ?int $failureWindowStartedAt = null,
        public readonly ?int $expiresAt = null,
        public readonly int $attemptCount = 0,
    ) {
    }

    /**
     * @return array{state: string, failure_count: int, success_count: int, failure_window_started_at: ?int, expires_at: ?int, attempt_count: int}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'failure_window_started_at' => $this->failureWindowStartedAt,
            'expires_at' => $this->expiresAt,
            'attempt_count' => $this->attemptCount,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        if (!\is_string($value['state'] ?? null)
            || !\is_int($value['failure_count'] ?? null)
            || !\is_int($value['success_count'] ?? null)
            || !\array_key_exists('failure_window_started_at', $value)
            || (null !== $value['failure_window_started_at'] && !\is_int($value['failure_window_started_at']))
            || !\array_key_exists('expires_at', $value)
            || (null !== $value['expires_at'] && !\is_int($value['expires_at']))
            || !\is_int($value['attempt_count'] ?? null)
        ) {
            throw InvalidCircuitRecordException::invalidRecord();
        }

        $state = CircuitState::tryFrom($value['state']) ?? throw InvalidCircuitRecordException::invalidState();

        /* @var array{state: string, failure_count: int, success_count: int, failure_window_started_at: ?int, expires_at: ?int, attempt_count: int} $value */
        return new self(
            $state,
            $value['failure_count'],
            $value['success_count'],
            $value['failure_window_started_at'],
            $value['expires_at'],
            $value['attempt_count'],
        );
    }
}
