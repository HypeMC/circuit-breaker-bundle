<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Command;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitBreaker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand(
    name: 'bizkit:circuit-breaker:close',
    description: 'Close the circuit breaker for an HTTP client',
)]
final class CircuitBreakerCloseCommand extends Command
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

        if (!$this->circuitBreakers->has($client)) {
            $output->writeln(\sprintf(
                '<error>Circuit breaker for HTTP client [%s] is not configured. Available clients: %s.</error>',
                $client,
                implode(', ', $this->circuitBreakerServices) ?: 'none',
            ));

            return self::FAILURE;
        }

        $this->circuitBreakers->get($client)->forceClose($serviceName);

        $output->writeln(\sprintf('<info>Circuit breaker for [%s] on client [%s] closed.</info>', $displayServiceName, $client));

        return self::SUCCESS;
    }
}
