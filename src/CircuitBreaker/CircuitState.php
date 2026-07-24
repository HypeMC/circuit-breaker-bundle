<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker;

enum CircuitState: string
{
    case Closed = 'closed';
    case HalfOpen = 'half_open';
    case Open = 'open';

    public function isClosed(): bool
    {
        return self::Closed === $this;
    }

    public function isHalfOpen(): bool
    {
        return self::HalfOpen === $this;
    }

    public function isOpen(): bool
    {
        return self::Open === $this;
    }
}
