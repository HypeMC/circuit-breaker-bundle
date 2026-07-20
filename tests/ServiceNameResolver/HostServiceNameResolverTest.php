<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\ServiceNameResolver;

use Bizkit\CircuitBreakerBundle\ServiceNameResolver\HostServiceNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(HostServiceNameResolver::class)]
final class HostServiceNameResolverTest extends TestCase
{
    #[TestWith(['https://api.example.com/v1/users', 'api.example.com'], 'hostname')]
    #[TestWith(['https://127.0.0.1/v1/users', '127.0.0.1'], 'IPv4 address')]
    #[TestWith(['https://[2001:db8::1]/v1/users', '[2001:db8::1]'], 'IPv6 address')]
    public function testResolvesServiceNameFromUrlHost(string $url, string $expectedServiceName): void
    {
        $resolver = new HostServiceNameResolver();

        self::assertSame($expectedServiceName, $resolver->resolve('GET', $url, []));
    }

    public function testReturnsNullForRelativeUrl(): void
    {
        $resolver = new HostServiceNameResolver();

        self::assertNull($resolver->resolve('GET', '/v1/users', []));
    }
}
