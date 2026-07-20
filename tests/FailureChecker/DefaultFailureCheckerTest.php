<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\FailureChecker;

use Bizkit\CircuitBreakerBundle\FailureChecker\DefaultFailureChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DefaultFailureChecker::class)]
final class DefaultFailureCheckerTest extends TestCase
{
    public function testTreatsTransportErrorAsFailure(): void
    {
        $checker = new DefaultFailureChecker();
        $chunk = new ErrorChunk(0, 'Network failure');

        try {
            self::assertTrue($checker($chunk, self::createContext(200), 'api'));
        } finally {
            $chunk->didThrow(true);
        }
    }

    #[TestWith([500, true])]
    #[TestWith([599, true])]
    #[TestWith([400, false])]
    #[TestWith([404, false])]
    #[TestWith([200, false])]
    public function testTreatsOnlyServerErrorStatusAsFailure(int $statusCode, bool $expected): void
    {
        $checker = new DefaultFailureChecker();

        self::assertSame($expected, $checker(new FirstChunk(), self::createContext($statusCode), 'api'));
    }

    public function testIgnoresNonFirstChunks(): void
    {
        $checker = new DefaultFailureChecker();

        self::assertFalse($checker(new DataChunk(), self::createContext(500), 'api'));
    }

    private static function createContext(int $statusCode): AsyncContext
    {
        $passthru = null;
        $client = new MockHttpClient();
        $response = new MockResponse('', ['http_code' => $statusCode]);
        $info = [];

        return new AsyncContext($passthru, $client, $response, $info, null, 0);
    }
}
