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
            && (!$record->state->isHalfOpen() || \count($record->attempts) < $this->config->halfOpenMaxConcurrentAttempts);
    }

    public function tryAcquireAttempt(string $serviceName): Attempt
    {
        $record = $this->refreshRecord($serviceName);

        if ($record->state->isOpen()) {
            return Attempt::blocked();
        }

        if ($record->state->isClosed()) {
            return Attempt::allowed();
        }

        if (\count($record->attempts) >= $this->config->halfOpenMaxConcurrentAttempts) {
            return Attempt::blocked();
        }

        $now = $this->timestamp();
        $attemptToken = self::createAttemptToken();

        $attemptExpiresAt = $now + $this->config->halfOpenAttemptTimeout;
        if (null !== $record->expiresAt) {
            $attemptExpiresAt = min($attemptExpiresAt, $record->expiresAt);
        }

        $this->storage->save(
            $serviceName,
            new CircuitRecord(
                CircuitState::HalfOpen,
                successCount: $record->successCount,
                expiresAt: $record->expiresAt,
                attempts: $record->attempts + [$attemptToken => $attemptExpiresAt],
            ),
            $this->ttlUntil($record->expiresAt),
        );

        return Attempt::allowed($attemptToken);
    }

    /**
     * Unlike {@see recordSuccess()}, this method intentionally takes no {@see Attempt}:
     * a failure always counts as evidence against the service, regardless of how
     * the request was admitted, while a success may only influence the circuit
     * when it comes from an attempt acquired via {@see tryAcquireAttempt()}.
     */
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

    public function recordSuccess(string $serviceName, Attempt $attempt): void
    {
        if ($attempt->isBlocked()) {
            return;
        }

        $record = $this->refreshRecord($serviceName);

        if ($record->state->isOpen()) {
            return;
        }

        if ($record->state->isClosed()) {
            return;
        }

        if (null === $attempt->getToken() || !isset($record->attempts[$attempt->getToken()])) {
            return;
        }

        $successCount = $record->successCount + 1;
        if ($successCount >= $this->config->successThreshold) {
            $this->closeCircuit($serviceName);

            return;
        }

        $record = $record->withoutAttempt($attempt->getToken());

        $this->storage->save(
            $serviceName,
            new CircuitRecord(
                CircuitState::HalfOpen,
                successCount: $successCount,
                expiresAt: $record->expiresAt,
                attempts: $record->attempts,
            ),
            $this->ttlUntil($record->expiresAt),
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
            $this->storage->save($serviceName, $record, $this->ttlUntil($halfOpenExpiresAt));

            return $record;
        }

        if ($record->state->isHalfOpen() && null !== $record->expiresAt && $now >= $record->expiresAt) {
            $this->closeCircuit($serviceName);

            return new CircuitRecord(CircuitState::Closed);
        }

        if ($record->state->isHalfOpen()) {
            $refreshedRecord = $record->withoutExpiredAttempts($now);
            if ($refreshedRecord !== $record) {
                $record = $refreshedRecord;
                $this->storage->save($serviceName, $record, $this->ttlUntil($record->expiresAt));
            }

            return $record;
        }

        if ($record->state->isClosed() && null !== $record->failureWindowStartedAt
            && $now >= $record->failureWindowStartedAt + $this->config->failureTimeWindow
        ) {
            $this->closeCircuit($serviceName);

            return new CircuitRecord(CircuitState::Closed);
        }

        return $record;
    }

    private function ttlUntil(?int $expiresAt): ?int
    {
        if (null === $expiresAt) {
            return null;
        }

        return max(1, $expiresAt - $this->timestamp() + 1);
    }

    private static function createAttemptToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function timestamp(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
