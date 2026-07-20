<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\ServiceNameResolver;

final class HostServiceNameResolver implements ServiceNameResolverInterface
{
    /**
     * {@inheritDoc}
     */
    public function resolve(string $method, string $url, array $options): ?string
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? $host : null;
    }
}
