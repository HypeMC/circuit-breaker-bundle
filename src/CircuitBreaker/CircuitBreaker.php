<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\CircuitRecord;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\StorageInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

final class CircuitBreaker
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly Settings $config = new Settings(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function allowsAttempt(string $serviceName): bool
    {
        $record = $this->refreshRecord($serviceName);

        return !$record->state->isOpen()
            && (!$record->state->isHalfOpen() || $record->attemptCount < $this->config->halfOpenMaxAttempts);
    }

    public function tryAcquireAttempt(string $serviceName): bool
    {
        $record = $this->refreshRecord($serviceName);

        if ($record->state->isOpen()) {
            return false;
        }

        if ($record->state->isClosed()) {
            return true;
        }

        if ($record->attemptCount >= $this->config->halfOpenMaxAttempts) {
            return false;
        }

        $this->storage->save(
            $serviceName,
            new CircuitRecord(
                CircuitState::HalfOpen,
                successCount: $record->successCount,
                expiresAt: $record->expiresAt,
                attemptCount: $record->attemptCount + 1,
            ),
            null === $record->expiresAt ? null : max(1, $record->expiresAt - $this->timestamp() + 1),
        );

        return true;
    }

    public function recordFailure(string $serviceName): void
    {
        $record = $this->refreshRecord($serviceName);

        if ($record->state->isOpen()) {
            return;
        }

        if ($record->state->isHalfOpen()) {
            $this->openCircuit($serviceName);

            return;
        }

        $now = $this->timestamp();
        $windowStartedAt = $record->failureWindowStartedAt;
        $failureCount = $record->failureCount;

        if (null === $windowStartedAt || $now >= $windowStartedAt + $this->config->failureTimeWindow) {
            $windowStartedAt = $now;
            $failureCount = 0;
        }

        ++$failureCount;

        if ($failureCount >= $this->config->failureThreshold) {
            $this->openCircuit($serviceName);

            return;
        }

        $this->storage->save(
            $serviceName,
            new CircuitRecord(CircuitState::Closed, $failureCount, 0, $windowStartedAt),
            $this->config->failureTimeWindow + 1,
        );
    }

    public function recordSuccess(string $serviceName): void
    {
        $record = $this->refreshRecord($serviceName);

        if ($record->state->isOpen()) {
            return;
        }

        if ($record->state->isClosed()) {
            return;
        }

        $successCount = $record->successCount + 1;
        if ($successCount >= $this->config->successThreshold) {
            $this->closeCircuit($serviceName);

            return;
        }

        $ttlSeconds = null === $record->expiresAt ? null : max(1, $record->expiresAt - $this->timestamp() + 1);
        $this->storage->save(
            $serviceName,
            new CircuitRecord(
                CircuitState::HalfOpen,
                successCount: $successCount,
                expiresAt: $record->expiresAt,
                attemptCount: max(0, $record->attemptCount - 1),
            ),
            $ttlSeconds,
        );
    }

    public function getState(string $serviceName): CircuitState
    {
        return $this->refreshRecord($serviceName)->state;
    }

    public function forceState(string $serviceName, CircuitState $state, ?int $ttlSeconds = null): void
    {
        if ($state->isClosed()) {
            $this->forceClose($serviceName);

            return;
        }

        if ($state->isOpen()) {
            $this->openCircuit($serviceName, $ttlSeconds);

            return;
        }

        $this->halfOpenCircuit($serviceName, $ttlSeconds);
    }

    public function forceClose(string $serviceName): void
    {
        $this->closeCircuit($serviceName);
    }

    private function openCircuit(string $serviceName, ?int $ttlSeconds = null): void
    {
        $duration = $ttlSeconds ?? $this->config->openTimeout;
        $this->storage->save(
            $serviceName,
            new CircuitRecord(CircuitState::Open, expiresAt: $this->timestamp() + $duration),
            $duration + $this->config->halfOpenTimeout + 1,
        );
    }

    private function halfOpenCircuit(string $serviceName, ?int $ttlSeconds = null): void
    {
        $duration = $ttlSeconds ?? $this->config->halfOpenTimeout;
        $this->storage->save(
            $serviceName,
            new CircuitRecord(CircuitState::HalfOpen, expiresAt: $this->timestamp() + $duration),
            $duration + 1,
        );
    }

    private function closeCircuit(string $serviceName): void
    {
        $this->storage->delete($serviceName);
    }

    private function refreshRecord(string $serviceName): CircuitRecord
    {
        $record = $this->storage->get($serviceName) ?? new CircuitRecord(CircuitState::Closed);
        $now = $this->timestamp();

        if ($record->state->isOpen() && null !== $record->expiresAt && $now >= $record->expiresAt) {
            if ($now >= $halfOpenExpiresAt = $record->expiresAt + $this->config->halfOpenTimeout) {
                $this->closeCircuit($serviceName);

                return new CircuitRecord(CircuitState::Closed);
            }

            $record = new CircuitRecord(CircuitState::HalfOpen, expiresAt: $halfOpenExpiresAt);
            $this->storage->save($serviceName, $record, max(1, $halfOpenExpiresAt - $now + 1));

            return $record;
        }

        if ($record->state->isHalfOpen() && null !== $record->expiresAt && $now >= $record->expiresAt) {
            $this->closeCircuit($serviceName);

            return new CircuitRecord(CircuitState::Closed);
        }

        if ($record->state->isClosed() && null !== $record->failureWindowStartedAt
            && $now >= $record->failureWindowStartedAt + $this->config->failureTimeWindow
        ) {
            $this->closeCircuit($serviceName);

            return new CircuitRecord(CircuitState::Closed);
        }

        return $record;
    }

    private function timestamp(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
