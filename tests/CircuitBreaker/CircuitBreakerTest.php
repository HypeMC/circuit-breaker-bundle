<?php

declare(strict_types=1);

namespace Bizkit\CircuitBreakerBundle\Tests\CircuitBreaker;

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
            halfOpenMaxAttempts: 1,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);

        self::assertTrue($circuitBreaker->allowsAttempt('api'));
        self::assertTrue($circuitBreaker->allowsAttempt('api'));
        self::assertTrue($circuitBreaker->tryAcquireAttempt('api'));
        self::assertFalse($circuitBreaker->allowsAttempt('api'));
        self::assertFalse($circuitBreaker->tryAcquireAttempt('api'));
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));
    }

    public function testHalfOpenReleasesAttemptAfterSuccessWhenSuccessThresholdIsNotReached(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            successThreshold: 2,
            openTimeout: 10,
            halfOpenMaxAttempts: 1,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);

        self::assertTrue($circuitBreaker->tryAcquireAttempt('api'));
        $circuitBreaker->recordSuccess('api');

        self::assertTrue($circuitBreaker->allowsAttempt('api'));
        self::assertTrue($circuitBreaker->tryAcquireAttempt('api'));
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

        $circuitBreaker->recordSuccess('api');
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));

        $circuitBreaker->recordSuccess('api');
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
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

    public function testLeakedHalfOpenAttemptBlocksUntilHalfOpenTimeoutThenCloses(): void
    {
        $clock = new MockClock();
        $circuitBreaker = new CircuitBreaker(new InMemoryStorage(), new Settings(
            failureThreshold: 1,
            openTimeout: 10,
            halfOpenTimeout: 5,
            halfOpenMaxAttempts: 1,
        ), $clock);

        $circuitBreaker->recordFailure('api');
        $clock->sleep(10);

        self::assertTrue($circuitBreaker->tryAcquireAttempt('api'));
        self::assertFalse($circuitBreaker->tryAcquireAttempt('api'));

        $clock->sleep(4);
        self::assertFalse($circuitBreaker->tryAcquireAttempt('api'));
        self::assertSame(CircuitState::HalfOpen, $circuitBreaker->getState('api'));

        $clock->sleep(1);
        self::assertSame(CircuitState::Closed, $circuitBreaker->getState('api'));
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
        $circuitBreaker->recordSuccess('api');
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

        $circuitBreaker->recordSuccess('api');

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
