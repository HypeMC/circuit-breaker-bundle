<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\Command;

use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerClearCommand;
use GabrielAnhaia\PhpCircuitBreaker\CircuitBreaker;
use GabrielAnhaia\PhpCircuitBreaker\CircuitState;
use GabrielAnhaia\PhpCircuitBreaker\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CircuitBreakerClearCommand::class)]
final class CircuitBreakerClearCommandTest extends TestCase
{
    public function testClearsCircuitBreakerOverride(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage);
        $circuitBreaker->forceState('api', CircuitState::OPEN);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['service' => 'api']));
        self::assertSame(CircuitState::CLOSED, $circuitBreaker->getState('api'));
        self::assertStringContainsString('Circuit breaker override for [api] cleared.', $tester->getDisplay());
    }

    public function testFailsForUnknownService(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::FAILURE, $tester->execute(['service' => 'missing']));
        self::assertStringContainsString('Circuit breaker for service [missing] is not configured. Available services: api.', $tester->getDisplay());
    }

    private static function createCommandTester(CircuitBreaker $circuitBreaker): CommandTester
    {
        return new CommandTester(new CircuitBreakerClearCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));
    }
}
