<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\Command;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitBreaker;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\InMemoryStorage;
use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerCloseCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CircuitBreakerCloseCommand::class)]
final class CircuitBreakerCloseCommandTest extends TestCase
{
    public function testClosesCircuitBreakerForDefaultServiceName(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $circuitBreaker->forceState('api', CircuitState::Open);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['client' => 'api']));
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
        self::assertStringContainsString('Circuit breaker for [api] on client [api] closed.', $tester->getDisplay());
    }

    public function testClosesCircuitBreakerForResolvedServiceName(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $circuitBreaker->forceState('api:example.com', CircuitState::Open);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['client' => 'api', 'service' => 'example.com']));
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api:example.com'));
        self::assertStringContainsString('Circuit breaker for [example.com] on client [api] closed.', $tester->getDisplay());
    }

    public function testFailsForUnknownClient(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::FAILURE, $tester->execute(['client' => 'missing']));
        self::assertStringContainsString('Circuit breaker for HTTP client [missing] is not configured. Available clients: api.', $tester->getDisplay());
    }

    public function testCompletesConfiguredHttpClientArgument(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = new CommandCompletionTester(new CircuitBreakerCloseCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
            'secondary' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));

        self::assertSame(['api', 'secondary'], $tester->complete(['']));
    }

    private static function createCommandTester(CircuitBreaker $circuitBreaker): CommandTester
    {
        return new CommandTester(new CircuitBreakerCloseCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));
    }
}
