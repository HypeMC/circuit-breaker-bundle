<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, CircuitRecord> */
    private array $records = [];

    /** @var array<string, int> */
    private array $expiresAt = [];

    public function __construct(
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function get(string $serviceName): ?CircuitRecord
    {
        if (isset($this->expiresAt[$serviceName]) && $this->timestamp() >= $this->expiresAt[$serviceName]) {
            $this->delete($serviceName);

            return null;
        }

        return $this->records[$serviceName] ?? null;
    }

    public function save(string $serviceName, CircuitRecord $record, ?int $ttlSeconds = null): void
    {
        $this->records[$serviceName] = $record;

        if (null === $ttlSeconds) {
            unset($this->expiresAt[$serviceName]);

            return;
        }

        $this->expiresAt[$serviceName] = $this->timestamp() + $ttlSeconds;
    }

    public function delete(string $serviceName): void
    {
        unset($this->records[$serviceName], $this->expiresAt[$serviceName]);
    }

    private function timestamp(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
