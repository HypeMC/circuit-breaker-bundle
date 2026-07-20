<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\Command;

use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerForceCommand;
use GabrielAnhaia\PhpCircuitBreaker\CircuitBreaker;
use GabrielAnhaia\PhpCircuitBreaker\CircuitState;
use GabrielAnhaia\PhpCircuitBreaker\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CircuitBreakerForceCommand::class)]
final class CircuitBreakerForceCommandTest extends TestCase
{
    public function testForcesCircuitBreakerState(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['service' => 'api', 'state' => 'open', '--ttl' => '60']));
        self::assertSame(CircuitState::OPEN, $circuitBreaker->getState('api'));
        self::assertStringContainsString('Circuit breaker for [api] forced to [open].', $tester->getDisplay());
    }

    public function testFailsForInvalidState(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::FAILURE, $tester->execute(['service' => 'api', 'state' => 'invalid']));
        self::assertStringContainsString('Invalid state [invalid]. Valid states: closed, open, half_open', $tester->getDisplay());
        self::assertSame(CircuitState::CLOSED, $circuitBreaker->getState('api'));
    }

    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['abc'])]
    #[TestWith(['10abc'])]
    public function testFailsForInvalidTtl(string $ttl): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::FAILURE, $tester->execute(['service' => 'api', 'state' => 'open', '--ttl' => $ttl]));
        self::assertStringContainsString(\sprintf('Invalid TTL [%s]. TTL must be a positive integer.', $ttl), $tester->getDisplay());
        self::assertSame(CircuitState::CLOSED, $circuitBreaker->getState('api'));
    }

    public function testFailsForUnknownService(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::FAILURE, $tester->execute(['service' => 'missing', 'state' => 'open']));
        self::assertStringContainsString('Circuit breaker for service [missing] is not configured. Available services: api.', $tester->getDisplay());
        self::assertSame(CircuitState::CLOSED, $circuitBreaker->getState('missing'));
    }

    private static function createCommandTester(CircuitBreaker $circuitBreaker): CommandTester
    {
        return new CommandTester(new CircuitBreakerForceCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));
    }
}
