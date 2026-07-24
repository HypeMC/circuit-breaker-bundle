<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\Command;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitBreaker;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\InMemoryStorage;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerOpenCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CircuitBreakerOpenCommand::class)]
final class CircuitBreakerOpenCommandTest extends TestCase
{
    public function testOpensCircuitBreakerForDefaultServiceName(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['client' => 'api', '--ttl' => '60']));
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
        self::assertStringContainsString('Circuit breaker for [api] on client [api] opened.', $tester->getDisplay());
    }

    public function testOpensCircuitBreakerForResolvedServiceName(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['client' => 'api', 'service' => 'example.com']));
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api:example.com'));
        self::assertStringContainsString('Circuit breaker for [example.com] on client [api] opened.', $tester->getDisplay());
    }

    public function testTtlControlsWhenOpenedCircuitBecomesHalfOpen(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), clock: $clock);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['client' => 'api', '--ttl' => '10']));
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));

        $clock->sleep(10);

        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));
    }

    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['abc'])]
    #[TestWith(['10abc'])]
    public function testFailsForInvalidTtl(string $ttl): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::FAILURE, $tester->execute(['client' => 'api', '--ttl' => $ttl]));
        self::assertStringContainsString(\sprintf('Invalid TTL [%s]. TTL must be a positive integer.', $ttl), $tester->getDisplay());
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    public function testFailsForUnknownClient(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::FAILURE, $tester->execute(['client' => 'missing']));
        self::assertStringContainsString('Circuit breaker for HTTP client [missing] is not configured. Available clients: api.', $tester->getDisplay());
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('missing'));
    }

    public function testCompletesConfiguredHttpClientArgument(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = new CommandCompletionTester(new CircuitBreakerOpenCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
            'secondary' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));

        self::assertSame(['api', 'secondary'], $tester->complete(['']));
    }

    private static function createCommandTester(CircuitBreaker $circuitBreaker): CommandTester
    {
        return new CommandTester(new CircuitBreakerOpenCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));
    }
}
