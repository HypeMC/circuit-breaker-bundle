<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\CircuitBreaker;

final class Attempt
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $token = null,
    ) {
    }

    public static function allowed(?string $token = null): self
    {
        return new self(true, $token);
    }

    public static function blocked(): self
    {
        return new self(false);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isBlocked(): bool
    {
        return !$this->allowed;
    }
}
