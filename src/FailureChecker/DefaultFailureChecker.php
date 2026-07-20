<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\FailureChecker;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Contracts\HttpClient\ChunkInterface;

final class DefaultFailureChecker implements FailureCheckerInterface
{
    public function __invoke(ChunkInterface $chunk, AsyncContext $context, string $serviceName): bool
    {
        if (null !== $chunk->getError()) {
            return true;
        }

        if (!$chunk->isFirst()) {
            return false;
        }

        $statusCode = $context->getStatusCode();

        return $statusCode >= 500 && $statusCode < 600;
    }
}
