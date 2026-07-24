<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\CircuitBreaker;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\Attempt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Attempt::class)]
final class AttemptTest extends TestCase
{
    public function testAllowedAttempt(): void
    {
        $attempt = Attempt::allowed('token');

        self::assertTrue($attempt->isAllowed());
        self::assertFalse($attempt->isBlocked());
        self::assertSame('token', $attempt->token);
    }

    public function testBlockedAttempt(): void
    {
        $attempt = Attempt::blocked();

        self::assertFalse($attempt->isAllowed());
        self::assertTrue($attempt->isBlocked());
        self::assertNull($attempt->token);
    }
}
