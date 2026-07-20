<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Command;

use GabrielAnhaia\PhpCircuitBreaker\CircuitBreaker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand(
    name: 'bizkit:circuit-breaker:clear',
    description: 'Clear a circuit breaker state override for a service',
)]
final class CircuitBreakerClearCommand extends Command
{
    /**
     * @param ServiceLocator<CircuitBreaker> $circuitBreakers
     */
    public function __construct(
        private readonly ServiceLocator $circuitBreakers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('service', InputArgument::REQUIRED, 'The service name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $service */
        $service = $input->getArgument('service');

        if (!$this->circuitBreakers->has($service)) {
            $output->writeln(\sprintf(
                '<error>Circuit breaker for service [%s] is not configured. Available services: %s.</error>',
                $service,
                implode(', ', array_keys($this->circuitBreakers->getProvidedServices())) ?: 'none',
            ));

            return self::FAILURE;
        }

        $this->circuitBreakers->get($service)->clearOverride($service);

        $output->writeln(\sprintf('<info>Circuit breaker override for [%s] cleared.</info>', $service));

        return self::SUCCESS;
    }
}
