<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Command;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitBreaker;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand(
    name: 'bizkit:circuit-breaker:open',
    description: 'Open the circuit breaker for an HTTP client',
)]
final class CircuitBreakerOpenCommand extends Command
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
            ->addArgument('service', InputArgument::OPTIONAL, 'The circuit breaker service name')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Optional time in seconds before the circuit becomes half-open')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $client */
        $client = $input->getArgument('client');
        /** @var ?string $service */
        $service = $input->getArgument('service');
        $serviceName = null === $service ? $client : $client.':'.$service;
        $displayServiceName = $service ?? $client;

        /** @var ?string $ttlOption */
        $ttlOption = $input->getOption('ttl');
        if (null !== $ttlOption && (!ctype_digit((string) $ttlOption) || 0 >= (int) $ttlOption)) {
            $output->writeln(\sprintf('<error>Invalid TTL [%s]. TTL must be a positive integer.</error>', $ttlOption));

            return self::FAILURE;
        }

        if (!$this->circuitBreakers->has($client)) {
            $output->writeln(\sprintf(
                '<error>Circuit breaker for HTTP client [%s] is not configured. Available clients: %s.</error>',
                $client,
                implode(', ', $this->circuitBreakerServices) ?: 'none',
            ));

            return self::FAILURE;
        }

        $this->circuitBreakers->get($client)->forceState(
            $serviceName,
            CircuitState::Open,
            null === $ttlOption ? null : (int) $ttlOption,
        );

        $output->writeln(\sprintf('<info>Circuit breaker for [%s] on client [%s] opened.</info>', $displayServiceName, $client));

        return self::SUCCESS;
    }
}
