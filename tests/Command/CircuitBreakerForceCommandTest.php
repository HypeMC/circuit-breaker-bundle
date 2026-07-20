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
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CircuitBreakerForceCommand::class)]
final class CircuitBreakerForceCommandTest extends TestCase
{
    public function testForcesCircuitBreakerStateForDefaultServiceName(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['client' => 'api', 'state' => 'open', '--ttl' => '60']));
        self::assertSame(CircuitState::OPEN, $circuitBreaker->getState('api'));
        self::assertStringContainsString('Circuit breaker for [api] on client [api] forced to [open].', $tester->getDisplay());
    }

    public function testForcesCircuitBreakerStateForResolvedServiceName(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['client' => 'api', 'state' => 'open', 'service' => 'example.com']));
        self::assertSame(CircuitState::CLOSED, $circuitBreaker->getState('api'));
        self::assertSame(CircuitState::OPEN, $circuitBreaker->getState('api:example.com'));
        self::assertStringContainsString('Circuit breaker for [example.com] on client [api] forced to [open].', $tester->getDisplay());
    }

    public function testFailsForInvalidState(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::FAILURE, $tester->execute(['client' => 'api', 'state' => 'invalid']));
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

        self::assertSame(Command::FAILURE, $tester->execute(['client' => 'api', 'state' => 'open', '--ttl' => $ttl]));
        self::assertStringContainsString(\sprintf('Invalid TTL [%s]. TTL must be a positive integer.', $ttl), $tester->getDisplay());
        self::assertSame(CircuitState::CLOSED, $circuitBreaker->getState('api'));
    }

    public function testFailsForUnknownClient(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::FAILURE, $tester->execute(['client' => 'missing', 'state' => 'open']));
        self::assertStringContainsString('Circuit breaker for HTTP client [missing] is not configured. Available clients: api.', $tester->getDisplay());
        self::assertSame(CircuitState::CLOSED, $circuitBreaker->getState('missing'));
    }

    public function testCompletesConfiguredHttpClientArgument(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = new CommandCompletionTester(new CircuitBreakerForceCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
            'secondary' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));

        self::assertSame(['api', 'secondary'], $tester->complete(['']));
    }

    public function testCompletesStateArgument(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = new CommandCompletionTester(new CircuitBreakerForceCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));

        self::assertSame(['closed', 'open', 'half_open'], $tester->complete(['api', '']));
    }

    private static function createCommandTester(CircuitBreaker $circuitBreaker): CommandTester
    {
        return new CommandTester(new CircuitBreakerForceCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));
    }
}
