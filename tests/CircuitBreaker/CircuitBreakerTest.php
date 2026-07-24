<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\CircuitBreaker;

use Bizkit\CircuitBreakerBundle\CircuitBreaker\Attempt;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitBreaker;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\CircuitState;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Settings;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\CircuitRecord;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\InMemoryStorage;
use Bizkit\CircuitBreakerBundle\CircuitBreaker\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CircuitBreaker::class)]
#[CoversClass(Settings::class)]
final class CircuitBreakerTest extends TestCase
{
    public function testStartsClosedAndAllowsRequests(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());

        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
        self::assertTrue($circuitBreaker->allowsAttempt('api'));

        $attempt = $circuitBreaker->tryAcquireAttempt('api');
        self::assertTrue($attempt->isAllowed());
        self::assertNull($attempt->getToken());
    }

    public function testOpensAfterFailureThresholdWithinFailureTimeWindow(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(failureThreshold: 2));

        $circuitBreaker->recordFailure('api');
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));

        $circuitBreaker->recordFailure('api');
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
    }

    public function testFailureWindowExpiresBeforeThresholdIsReached(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(failureThreshold: 2, failureTimeWindow: 10), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);
        $circuitBreaker->recordFailure('api');

        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    public function testDoesNotAllowAttemptsWhenOpen(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(failureThreshold: 1));
        $circuitBreaker->recordFailure('api');

        self::assertFalse($circuitBreaker->allowsAttempt('api'));
    }

    public function testTransitionsOpenToHalfOpenAfterOpenTimeout(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(failureThreshold: 1, openTimeout: 10), $clock);

        $circuitBreaker->recordFailure('api');
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));

        $clock->sleep(10);

        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));
        self::assertTrue($circuitBreaker->allowsAttempt('api'));
    }

    public function testHalfOpenAllowsOnlyConfiguredAttempts(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            openTimeout: 10,
            halfOpenMaxConcurrentAttempts: 1,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);

        self::assertTrue($circuitBreaker->allowsAttempt('api'));
        self::assertTrue($circuitBreaker->allowsAttempt('api'));
        self::assertTrue($circuitBreaker->tryAcquireAttempt('api')->isAllowed());
        self::assertFalse($circuitBreaker->allowsAttempt('api'));
        self::assertFalse($circuitBreaker->tryAcquireAttempt('api')->isAllowed());
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));
    }

    public function testHalfOpenReleasesAttemptAfterSuccessWhenSuccessThresholdIsNotReached(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            successThreshold: 2,
            openTimeout: 10,
            halfOpenMaxConcurrentAttempts: 1,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);

        $attempt = $circuitBreaker->tryAcquireAttempt('api');
        self::assertTrue($attempt->isAllowed());
        self::assertNotNull($attempt->getToken());

        $circuitBreaker->recordSuccess('api', $attempt);

        self::assertTrue($circuitBreaker->allowsAttempt('api'));
        self::assertTrue($circuitBreaker->tryAcquireAttempt('api')->isAllowed());
    }

    public function testHalfOpenSuccessReleasesOnlyMatchingAttempt(): void
    {
        $clock = new MockClock();
        $storage = new InMemoryStorage();
        $circuitBreaker = new CircuitBreaker($storage, new Settings(
            failureThreshold: 1,
            successThreshold: 3,
            openTimeout: 10,
            halfOpenMaxConcurrentAttempts: 2,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);

        $firstAttempt = $circuitBreaker->tryAcquireAttempt('api');
        $secondAttempt = $circuitBreaker->tryAcquireAttempt('api');
        self::assertNotNull($firstAttempt->getToken());
        self::assertNotNull($secondAttempt->getToken());

        $circuitBreaker->recordSuccess('api', $secondAttempt);

        $attempts = $storage->get('api')?->attempts;
        self::assertIsArray($attempts);
        self::assertArrayHasKey($firstAttempt->getToken(), $attempts);
        self::assertArrayNotHasKey($secondAttempt->getToken(), $attempts);
    }

    public function testHalfOpenRequiresSuccessThresholdBeforeClosing(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            successThreshold: 2,
            openTimeout: 10,
            halfOpenTimeout: 10,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));

        $firstAttempt = $circuitBreaker->tryAcquireAttempt('api');
        $circuitBreaker->recordSuccess('api', $firstAttempt);
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));

        $secondAttempt = $circuitBreaker->tryAcquireAttempt('api');
        $circuitBreaker->recordSuccess('api', $secondAttempt);
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    public function testBlockedAttemptDoesNotRecordSuccess(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            successThreshold: 1,
            openTimeout: 10,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);

        $circuitBreaker->recordSuccess('api', Attempt::blocked());

        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));
    }

    public function testHalfOpenFailureReopensCircuit(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(failureThreshold: 1, openTimeout: 10), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));

        $circuitBreaker->recordFailure('api');
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
    }

    public function testHalfOpenTimeoutClosesCircuitWithoutAnAttemptResult(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            openTimeout: 10,
            halfOpenTimeout: 5,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));

        $clock->sleep(5);
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    public function testExpiredHalfOpenAttemptAllowsAnotherAttemptBeforeHalfOpenTimeout(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            openTimeout: 10,
            halfOpenTimeout: 10,
            halfOpenMaxConcurrentAttempts: 1,
            halfOpenAttemptTimeout: 2,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);

        self::assertTrue($circuitBreaker->tryAcquireAttempt('api')->isAllowed());
        self::assertFalse($circuitBreaker->tryAcquireAttempt('api')->isAllowed());

        $clock->sleep(1);
        self::assertFalse($circuitBreaker->tryAcquireAttempt('api')->isAllowed());
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));

        $clock->sleep(1);
        self::assertTrue($circuitBreaker->tryAcquireAttempt('api')->isAllowed());
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));
    }

    public function testOpenCircuitClosesWhenOpenAndHalfOpenTimeoutsElapsedWithoutAnAttemptResult(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            openTimeout: 10,
            halfOpenTimeout: 5,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(15);

        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    public function testLazyHalfOpenTransitionKeepsOriginalHalfOpenDeadline(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            openTimeout: 10,
            halfOpenTimeout: 5,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(14);
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));

        $clock->sleep(1);
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    public function testSuccessfulClosedRequestDoesNotClearFailureCounter(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(failureThreshold: 2));

        $circuitBreaker->recordFailure('api');
        $circuitBreaker->recordSuccess('api', $circuitBreaker->tryAcquireAttempt('api'));
        $circuitBreaker->recordFailure('api');

        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));
    }

    public function testSuccessfulClosedRequestWithoutStoredFailuresDoesNotDeleteStorage(): void
    {
        $storage = new class implements StorageInterface {
            public int $deleteCount = 0;

            public function get(string $serviceName): ?CircuitRecord
            {
                return null;
            }

            public function save(string $serviceName, CircuitRecord $record, ?int $ttlSeconds = null): void
            {
            }

            public function delete(string $serviceName): void
            {
                ++$this->deleteCount;
            }
        };
        $circuitBreaker = new CircuitBreaker($storage);

        $circuitBreaker->recordSuccess('api', $circuitBreaker->tryAcquireAttempt('api'));

        self::assertSame(0, $storage->deleteCount);
    }

    public function testForceStateWritesActualState(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());

        $circuitBreaker->forceState('api', CircuitState::Open);
        self::assertSame(CircuitState::Open, $circuitBreaker->getState('api'));

        $circuitBreaker->forceState('api', CircuitState::HalfOpen);
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));

        $circuitBreaker->forceState('api', CircuitState::Closed);
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }

    public function testForceCloseRemovesActualState(): void
    {
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage());

        $circuitBreaker->forceState('api', CircuitState::Open);
        $circuitBreaker->forceClose('api');

        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
    }
}
