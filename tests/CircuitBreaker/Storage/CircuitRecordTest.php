<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\CircuitBreaker\Storage;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\CircuitRecord;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\Exception\InvalidCircuitRecordException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(CircuitRecord::class)]
#[CoversClass(InvalidCircuitRecordException::class)]
final class CircuitRecordTest extends TestCase
{
    public function testConvertsToAndFromArray(): void
    {
        $record = new CircuitRecord(CircuitState::HalfOpen, 2, 1, 10, 20, ['first' => 25, 'second' => 30]);

        $value = $record->toArray();

        self::assertSame([
            'state' => 'half_open',
            'failure_count' => 2,
            'success_count' => 1,
            'failure_window_started_at' => 10,
            'expires_at' => 20,
            'attempts' => ['first' => 25, 'second' => 30],
        ], $value);
        self::assertEquals($record, CircuitRecord::fromArray($value));
    }

    public function testReturnsActiveAttempts(): void
    {
        $record = new CircuitRecord(CircuitState::HalfOpen, attempts: [
            'expired' => 10,
            'active' => 11,
        ]);

        self::assertSame(['active' => 11], $record->activeAttempts(10));
    }

    public function testCreatesRecordWithOnlyActiveAttempts(): void
    {
        $record = new CircuitRecord(CircuitState::HalfOpen, successCount: 1, expiresAt: 20, attempts: [
            'expired' => 10,
            'active' => 11,
        ]);

        $refreshedRecord = $record->withActiveAttempts(10);

        self::assertNotSame($record, $refreshedRecord);
        self::assertSame(['active' => 11], $refreshedRecord->attempts);
        self::assertSame(1, $refreshedRecord->successCount);
        self::assertSame(20, $refreshedRecord->expiresAt);
    }

    public function testReturnsSameRecordWhenAllAttemptsAreActive(): void
    {
        $record = new CircuitRecord(CircuitState::HalfOpen, attempts: ['active' => 11]);

        self::assertSame($record, $record->withActiveAttempts(10));
    }

    public function testRemovesMatchingAttempt(): void
    {
        $record = new CircuitRecord(CircuitState::HalfOpen, attempts: [
            'first' => 20,
            'second' => 30,
        ]);

        $refreshedRecord = $record->withoutAttempt('second');

        self::assertSame(['first' => 20], $refreshedRecord->attempts);
    }

    public function testReturnsSameRecordWhenRemovingMissingAttempt(): void
    {
        $record = new CircuitRecord(CircuitState::HalfOpen, attempts: ['first' => 20]);

        self::assertSame($record, $record->withoutAttempt('missing'));
        self::assertSame($record, $record->withoutAttempt(null));
    }

    /**
     * @param array<string, mixed> $value
     */
    #[TestWith([[]])]
    #[TestWith([['state' => 'open', 'failure_count' => '1', 'success_count' => 0, 'failure_window_started_at' => null, 'expires_at' => null, 'attempts' => []]])]
    #[TestWith([['state' => 'open', 'failure_count' => 1, 'success_count' => 0, 'expires_at' => null]], 'missing failure window')]
    #[TestWith([['state' => 'open', 'failure_count' => 1, 'success_count' => 0, 'failure_window_started_at' => null, 'expires_at' => null]], 'missing attempts')]
    #[TestWith([['state' => 'open', 'failure_count' => 1, 'success_count' => 0, 'failure_window_started_at' => null, 'expires_at' => null, 'attempts' => ['token' => '1']]], 'non-integer attempt expiry')]
    #[TestWith([['state' => 'open', 'failure_count' => 1, 'success_count' => 0, 'failure_window_started_at' => null, 'expires_at' => null, 'attempts' => [1 => 10]]], 'non-string attempt token')]
    public function testRejectsInvalidArrayShape(array $value): void
    {
        $this->expectException(InvalidCircuitRecordException::class);
        $this->expectExceptionMessage('Invalid circuit breaker record.');

        CircuitRecord::fromArray($value);
    }

    public function testRejectsInvalidState(): void
    {
        $this->expectException(InvalidCircuitRecordException::class);
        $this->expectExceptionMessage('Invalid circuit breaker record state.');

        CircuitRecord::fromArray([
            'state' => 'invalid',
            'failure_count' => 1,
            'success_count' => 0,
            'failure_window_started_at' => null,
            'expires_at' => null,
            'attempts' => [],
        ]);
    }
}
