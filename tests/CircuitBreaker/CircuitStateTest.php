<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\CircuitBreaker;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CircuitState::class)]
final class CircuitStateTest extends TestCase
{
    public function testCircuitStatePredicates(): void
    {
        self::assertTrue(CircuitState::Closed->isClosed());
        self::assertFalse(CircuitState::Closed->isHalfOpen());
        self::assertFalse(CircuitState::Closed->isOpen());

        self::assertFalse(CircuitState::HalfOpen->isClosed());
        self::assertTrue(CircuitState::HalfOpen->isHalfOpen());
        self::assertFalse(CircuitState::HalfOpen->isOpen());

        self::assertFalse(CircuitState::Open->isClosed());
        self::assertFalse(CircuitState::Open->isHalfOpen());
        self::assertTrue(CircuitState::Open->isOpen());
    }
}
