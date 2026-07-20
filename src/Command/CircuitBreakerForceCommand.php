<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Command;

use GabrielAnhaia\PhpCircuitBreaker\CircuitBreaker;
use GabrielAnhaia\PhpCircuitBreaker\CircuitState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand(
    name: 'bizkit:circuit-breaker:force',
    description: 'Force a circuit breaker state override for a service',
)]
final class CircuitBreakerForceCommand extends Command
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
        $this
            ->addArgument('service', InputArgument::REQUIRED, 'The service name')
            ->addArgument('state', InputArgument::REQUIRED, 'The state to force (closed, open, half_open)')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Optional TTL in seconds for the override')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $service */
        $service = $input->getArgument('service');
        /** @var string $stateValue */
        $stateValue = $input->getArgument('state');

        if (null === $state = CircuitState::tryFrom($stateValue)) {
            $valid = implode(', ', array_map(static fn (CircuitState $state): string => $state->value, CircuitState::cases()));
            $output->writeln(\sprintf('<error>Invalid state [%s]. Valid states: %s</error>', $stateValue, $valid));

            return self::FAILURE;
        }

        /** @var string|null $ttlOption */
        $ttlOption = $input->getOption('ttl');
        if (null !== $ttlOption && (!ctype_digit((string) $ttlOption) || 0 >= (int) $ttlOption)) {
            $output->writeln(\sprintf('<error>Invalid TTL [%s]. TTL must be a positive integer.</error>', $ttlOption));

            return self::FAILURE;
        }

        $ttl = null === $ttlOption ? null : (int) $ttlOption;

        if (!$this->circuitBreakers->has($service)) {
            $output->writeln(\sprintf(
                '<error>Circuit breaker for service [%s] is not configured. Available services: %s.</error>',
                $service,
                implode(', ', array_keys($this->circuitBreakers->getProvidedServices())) ?: 'none',
            ));

            return self::FAILURE;
        }

        $this->circuitBreakers->get($service)->forceState($service, $state, $ttl);

        $output->writeln(\sprintf('<info>Circuit breaker for [%s] forced to [%s].</info>', $service, $state->value));

        return self::SUCCESS;
    }
}
