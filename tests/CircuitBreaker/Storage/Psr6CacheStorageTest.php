<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\CircuitBreaker\Storage;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\CircuitRecord;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\Exception\InvalidCircuitRecordException;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\Psr6CacheStorage;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\StorageInterface;
use Bizkit\CircuitBreakerBundle\Tests\Fixtures\TestCacheItemPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Psr6CacheStorage::class)]
#[CoversClass(StorageInterface::class)]
final class Psr6CacheStorageTest extends TestCase
{
    public function testReturnsNullWhenRecordIsMissing(): void
    {
        $storage = new Psr6CacheStorage(new TestCacheItemPool());

        self::assertNull($storage->get('api'));
    }

    public function testStoresAndDeletesRecord(): void
    {
        $storage = new Psr6CacheStorage(new TestCacheItemPool());
        $record = new CircuitRecord(CircuitState::Open, 2, 1, 10, 20);

        $storage->save('api', $record, 30);

        self::assertEquals($record, $storage->get('api'));

        $storage->delete('api');
        self::assertNull($storage->get('api'));
    }

    public function testServiceNamesWithSimilarSanitizedFormsDoNotCollide(): void
    {
        $storage = new Psr6CacheStorage(new TestCacheItemPool());

        $storage->save('api:payments', new CircuitRecord(CircuitState::Open));
        $storage->save('api_payments', new CircuitRecord(CircuitState::HalfOpen));

        self::assertSame(CircuitState::Open, $storage->get('api:payments')?->state);
        self::assertSame(CircuitState::HalfOpen, $storage->get('api_payments')?->state);
    }

    public function testThrowsWhenCachedValueIsNotAnArray(): void
    {
        $pool = new TestCacheItemPool();
        $storage = new Psr6CacheStorage($pool);
        $item = $pool->getItem(self::key('api'));
        $item->set('invalid');
        $pool->save($item);

        $this->expectException(InvalidCircuitRecordException::class);

        $storage->get('api');
    }

    private static function key(string $serviceName): string
    {
        return substr(hash('sha256', $serviceName), 0, 32);
    }
}
