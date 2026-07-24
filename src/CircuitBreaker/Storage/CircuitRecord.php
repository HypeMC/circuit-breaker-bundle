<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Exception\InvalidCircuitRecordException;

final class CircuitRecord
{
    /**
     * @param array<string, int> $attempts
     */
    public function __construct(
        public readonly CircuitState $state,
        public readonly int $failureCount = 0,
        public readonly int $successCount = 0,
        public readonly ?int $failureWindowStartedAt = null,
        public readonly ?int $expiresAt = null,
        public readonly array $attempts = [],
    ) {
    }

    /**
     * @return array{state: string, failure_count: int, success_count: int, failure_window_started_at: ?int, expires_at: ?int, attempts: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'failure_window_started_at' => $this->failureWindowStartedAt,
            'expires_at' => $this->expiresAt,
            'attempts' => $this->attempts,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function activeAttempts(int $now): array
    {
        return array_filter(
            $this->attempts,
            static fn (int $expiresAt): bool => $expiresAt > $now,
        );
    }

    public function withoutExpiredAttempts(int $now): self
    {
        $attempts = $this->activeAttempts($now);
        if ($attempts === $this->attempts) {
            return $this;
        }

        return new self(
            $this->state,
            $this->failureCount,
            $this->successCount,
            $this->failureWindowStartedAt,
            $this->expiresAt,
            $attempts,
        );
    }

    public function withoutAttempt(?string $token): self
    {
        if (null === $token || !isset($this->attempts[$token])) {
            return $this;
        }

        $attempts = $this->attempts;
        unset($attempts[$token]);

        return new self(
            $this->state,
            $this->failureCount,
            $this->successCount,
            $this->failureWindowStartedAt,
            $this->expiresAt,
            $attempts,
        );
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
            || !self::isAttemptMap($value['attempts'] ?? null)
        ) {
            throw InvalidCircuitRecordException::invalidRecord();
        }

        $state = CircuitState::tryFrom($value['state']) ?? throw InvalidCircuitRecordException::invalidState();

        /* @var array{state: string, failure_count: int, success_count: int, failure_window_started_at: ?int, expires_at: ?int, attempts: array<string, int>} $value */
        return new self(
            $state,
            $value['failure_count'],
            $value['success_count'],
            $value['failure_window_started_at'],
            $value['expires_at'],
            $value['attempts'],
        );
    }

    private static function isAttemptMap(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $token => $expiresAt) {
            if (!\is_string($token) || '' === $token || !\is_int($expiresAt)) {
                return false;
            }
        }

        return true;
    }
}
