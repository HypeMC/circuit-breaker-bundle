<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\Exception\InvalidCircuitRecordException;
use Psr\Cache\CacheItemPoolInterface;

final class Psr6CacheStorage implements StorageInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function get(string $serviceName): ?CircuitRecord
    {
        $item = $this->cache->getItem($this->key($serviceName));

        if (!$item->isHit()) {
            return null;
        }

        if (!\is_array($value = $item->get())) {
            throw InvalidCircuitRecordException::invalidRecord();
        }

        return CircuitRecord::fromArray($value);
    }

    public function save(string $serviceName, CircuitRecord $record, ?int $ttlSeconds = null): void
    {
        $item = $this->cache->getItem($this->key($serviceName));
        $item->set($record->toArray());

        if (null !== $ttlSeconds) {
            $item->expiresAfter($ttlSeconds);
        }

        $this->cache->save($item);
    }

    public function delete(string $serviceName): void
    {
        $this->cache->deleteItem($this->key($serviceName));
    }

    private function key(string $serviceName): string
    {
        return substr(hash('sha256', $serviceName), 0, 32);
    }
}
