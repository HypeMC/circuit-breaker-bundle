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
        $record = new CircuitRecord(CircuitState::HalfOpen, 2, 1, 10, 20, 1);

        $value = $record->toArray();

        self::assertSame([
            'state' => 'half_open',
            'failure_count' => 2,
            'success_count' => 1,
            'failure_window_started_at' => 10,
            'expires_at' => 20,
            'attempt_count' => 1,
        ], $value);
        self::assertEquals($record, CircuitRecord::fromArray($value));
    }

    /**
     * @param array<string, mixed> $value
     */
    #[TestWith([[]])]
    #[TestWith([['state' => 'open', 'failure_count' => '1', 'success_count' => 0, 'failure_window_started_at' => null, 'expires_at' => null, 'attempt_count' => 0]])]
    #[TestWith([['state' => 'open', 'failure_count' => 1, 'success_count' => 0, 'expires_at' => null]], 'missing failure window')]
    #[TestWith([['state' => 'open', 'failure_count' => 1, 'success_count' => 0, 'failure_window_started_at' => null, 'expires_at' => null]], 'missing attempt count')]
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
            'attempt_count' => 0,
        ]);
    }
}
