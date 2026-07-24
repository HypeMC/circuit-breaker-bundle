<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage;

interface StorageInterface
{
    public function get(string $serviceName): ?CircuitRecord;

    public function save(string $serviceName, CircuitRecord $record, ?int $ttlSeconds = null): void;

    public function delete(string $serviceName): void;
}
