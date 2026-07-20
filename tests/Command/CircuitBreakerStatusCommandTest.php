<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\Command;

use Bizkit\CircuitBreakerBundle\Command\CircuitBreakerStatusCommand;
use GabrielAnhaia\PhpCircuitBreaker\CircuitBreaker;
use GabrielAnhaia\PhpCircuitBreaker\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CircuitBreakerStatusCommand::class)]
final class CircuitBreakerStatusCommandTest extends TestCase
{
    public function testShowsCircuitBreakerState(): void
    {
        $storage = new InMemoryStorage();
        $storage->setOpen('api', 30);
        $circuitBreaker = new CircuitBreaker($storage);
        $tester = self::createCommandTester($circuitBreaker);

        self::assertSame(Command::SUCCESS, $tester->execute(['service' => 'api']));
        self::assertStringContainsString('Circuit breaker state for [api]: open', $tester->getDisplay());
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
        return new CommandTester(new CircuitBreakerStatusCommand(new ServiceLocator([
            'api' => static fn (): CircuitBreaker => $circuitBreaker,
        ])));
    }
}
