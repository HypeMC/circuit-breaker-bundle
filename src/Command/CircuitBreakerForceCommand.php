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
    description: 'Force a circuit breaker state override for an HTTP client',
)]
final class CircuitBreakerForceCommand extends Command
{
    /**
     * @var list<string>
     */
    private readonly array $circuitBreakerServices;

    /**
     * @param ServiceLocator<CircuitBreaker> $circuitBreakers
     */
    public function __construct(
        private readonly ServiceLocator $circuitBreakers,
    ) {
        $this->circuitBreakerServices = array_keys($this->circuitBreakers->getProvidedServices());

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'client',
                InputArgument::REQUIRED,
                'The configured HTTP client service ID',
                null,
                $this->circuitBreakerServices,
            )
            ->addArgument(
                'state',
                InputArgument::REQUIRED,
                'The state to force (closed, open, half_open)',
                null,
                array_map(static fn (CircuitState $state): string => $state->value, CircuitState::cases()),
            )
            ->addArgument('service', InputArgument::OPTIONAL, 'The circuit breaker service name')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Optional TTL in seconds for the override')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $client */
        $client = $input->getArgument('client');
        /** @var string $stateValue */
        $stateValue = $input->getArgument('state');
        /** @var ?string $service */
        $service = $input->getArgument('service');
        $serviceName = null === $service ? $client : $client.':'.$service;
        $displayServiceName = $service ?? $client;

        if (null === $state = CircuitState::tryFrom($stateValue)) {
            $valid = implode(', ', array_map(static fn (CircuitState $state): string => $state->value, CircuitState::cases()));
            $output->writeln(\sprintf('<error>Invalid state [%s]. Valid states: %s</error>', $stateValue, $valid));

            return self::FAILURE;
        }

        /** @var ?string $ttlOption */
        $ttlOption = $input->getOption('ttl');
        if (null !== $ttlOption && (!ctype_digit((string) $ttlOption) || 0 >= (int) $ttlOption)) {
            $output->writeln(\sprintf('<error>Invalid TTL [%s]. TTL must be a positive integer.</error>', $ttlOption));

            return self::FAILURE;
        }

        $ttl = null === $ttlOption ? null : (int) $ttlOption;

        if (!$this->circuitBreakers->has($client)) {
            $output->writeln(\sprintf(
                '<error>Circuit breaker for HTTP client [%s] is not configured. Available clients: %s.</error>',
                $client,
                implode(', ', $this->circuitBreakerServices) ?: 'none',
            ));

            return self::FAILURE;
        }

        $this->circuitBreakers->get($client)->forceState($serviceName, $state, $ttl);

        $output->writeln(\sprintf('<info>Circuit breaker for [%s] on client [%s] forced to [%s].</info>', $displayServiceName, $client, $state->value));

        return self::SUCCESS;
    }
}
