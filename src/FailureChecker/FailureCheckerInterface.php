<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\FailureChecker;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Contracts\HttpClient\ChunkInterface;

interface FailureCheckerInterface
{
    public function __invoke(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool;
}
