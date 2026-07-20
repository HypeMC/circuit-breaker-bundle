<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\ServiceNameResolver;

interface ServiceNameResolverInterface
{
    /**
     * @param array<string, mixed> $options
     *
     * @return ?non-empty-string
     */
    public function resolve(string $method, string $url, array $options): ?string;
}
