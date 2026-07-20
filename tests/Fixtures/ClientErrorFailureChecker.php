<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\Fixtures;

use Bizkit\CircuitBreakerBundle\FailureChecker\FailureCheckerInterface;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Contracts\HttpClient\ChunkInterface;

final class ClientErrorFailureChecker implements FailureCheckerInterface
{
    public function __invoke(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool
    {
        return null !== $chunk->getError() || ($chunk->isFirst() && $context->getStatusCode() >= 400);
    }
}
