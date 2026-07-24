<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\CircuitBreaker\Storage;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\CircuitRecord;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\InMemoryStorage;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(InMemoryStorage::class)]
#[CoversClass(StorageInterface::class)]
final class InMemoryStorageTest extends TestCase
{
    public function testStoresAndDeletesRecord(): void
    {
        $storage = new InMemoryStorage();
        $record = new CircuitRecord(CircuitState::Open);

        $storage->save('api', $record);
        self::assertSame($record, $storage->get('api'));

        $storage->delete('api');
        self::assertNull($storage->get('api'));
    }

    public function testExpiresRecordAfterTtl(): void
    {
        $clock = new MockClock();
        $storage = new InMemoryStorage($clock);
        $record = new CircuitRecord(CircuitState::Open);

        $storage->save('api', $record, 10);
        self::assertSame($record, $storage->get('api'));

        $clock->sleep(10);
        self::assertNull($storage->get('api'));
    }

    public function testSavingWithoutTtlClearsPreviousExpiration(): void
    {
        $storage = new InMemoryStorage();
        $record = new CircuitRecord(CircuitState::Open);

        $storage->save('api', new CircuitRecord(CircuitState::HalfOpen), 0);
        $storage->save('api', $record);

        self::assertSame($record, $storage->get('api'));
    }
}
