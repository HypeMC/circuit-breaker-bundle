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
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CircuitBreakerClearCommand::class)]
final class CircuitBreakerClearCommandTest extends TestCase
{
    public function testClearsCircuitBreakerOverrideForDefaultServiceName(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage);
        $circuitBreaker->forceState('api', CircuitState::OPEN);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['client' => 'api']));
        self::assertSame(CircuitState::CLOSED, $circuitBreaker->getState('api'));
        self::assertStringContainsString('Circuit breaker override for [api] on client [api] cleared.', $tester->getDisplay());
    }

    public function testClearsCircuitBreakerOverrideForResolvedServiceName(): void
    {
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage);
        $circuitBreaker->forceState('api:example.com', CircuitState::OPEN);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['client' => 'api', 'service' => 'example.com']));
        self::assertSame(CircuitState::CLOSED, $circuitBreaker->getState('api:example.com'));
        self::assertStringContainsString('Circuit breaker override for [example.com] on client [api] cleared.', $tester->getDisplay());
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
        $tester = new CommandCompletionTester(new CircuitBreakerClearCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
            'secondary' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));

        self::assertSame(['api', 'secondary'], $tester->complete(['']));
    }

    private static function createCommandTester(CircuitBreaker $circuitBreaker): CommandTester
    {
        return new CommandTester(new CircuitBreakerClearCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));
    }
}
