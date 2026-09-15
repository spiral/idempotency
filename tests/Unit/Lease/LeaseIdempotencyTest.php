<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Lease;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Spiral\Idempotency\Exception\CachedDomainFailureException;
use Spiral\Idempotency\Exception\IdempotencyException;
use Spiral\Idempotency\Exception\LeaseLostException;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Lease\AcquireResult;
use Spiral\Idempotency\Lease\Acquired;
use Spiral\Idempotency\Lease\LeaseManager;
use Spiral\Idempotency\Lease\LeaseStorage;
use Spiral\Idempotency\Lease\StoredEntry;
use Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\ReplayableFailure;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Idempotency\Uncacheable;
use Spiral\Serializer\Serializer\PhpSerializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * A domain failure that opts into faithful, exact-type replay by carrying a JSON-safe scalar payload.
 */
final class PaymentDeclinedStub extends \DomainException implements ReplayableFailure
{
    public function __construct(
        public readonly int $declineCode,
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }

    public function toReplayPayload(): array
    {
        return ['declineCode' => $this->declineCode, 'reason' => $this->reason];
    }

    public static function fromReplayPayload(array $payload): static
    {
        return new self((int) $payload['declineCode'], (string) $payload['reason']);
    }
}

#[Test]
#[Covers(LeaseIdempotency::class)]
final class LeaseIdempotencyTest
{
    private function driver(?MutableClock $clock = null, ?DefaultFailureClassifier $classifier = null): LeaseIdempotency
    {
        $clock ??= new MutableClock();
        $classifier ??= new DefaultFailureClassifier();
        $manager = new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock);
        $execution = new Pipeline(new ClassifierMiddleware($classifier));

        return new LeaseIdempotency($manager, $execution, lockTtl: 30, retentionTtl: 3600, classifier: $classifier);
    }

    public function executesAndReturnsResult(): void
    {
        $result = $this->driver()->execute('k', static fn(IdempotencyContext $c): string => 'done:' . $c->getKey());

        Assert::same($result, 'done:k');
    }

    public function replaysCachedResultWithoutReExecuting(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            return 'value';
        };

        $first = $driver->execute('k', $op);
        $second = $driver->execute('k', $op);

        Assert::same($first, 'value');
        Assert::same($second, 'value');
        Assert::same($calls, 1);
    }

    public function voidResultRoundTrips(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): null {
            ++$calls;
            return null;
        };

        Assert::null($driver->execute('k', $op));
        Assert::null($driver->execute('k', $op));
        Assert::same($calls, 1);
    }

    public function forcedHeartbeatReachesTheLeaseManager(): void
    {
        // Proves the context -> HeartbeatThrottle -> DefaultLeaseManager wiring: a forced renew() call from the
        // operation bypasses the throttle and reaches LeaseManager::renew() on the real manager.
        $clock = new MutableClock();
        $inner = new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock);
        $spy = new class($inner) implements LeaseManager {
            public int $renewCalls = 0;

            public function __construct(private readonly LeaseManager $inner) {}

            public function acquire(string $key, int $lockTtl): AcquireResult
            {
                return $this->inner->acquire($key, $lockTtl);
            }

            public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): void
            {
                $this->inner->complete($key, $token, $success, $result, $retentionTtl);
            }

            public function abort(string $key, string $token): void
            {
                $this->inner->abort($key, $token);
            }

            public function error(string $key, string $token): void
            {
                $this->inner->error($key, $token);
            }

            public function renew(string $key, string $token, int $lockTtl): void
            {
                $this->renewCalls++;
                $this->inner->renew($key, $token, $lockTtl);
            }
        };

        $driver = new LeaseIdempotency($spy, new Pipeline(), lockTtl: 30, retentionTtl: 3600, clock: $clock);

        $result = $driver->execute('k', static function (IdempotencyContext $c): string {
            $c->renew(true);
            $c->renew(true);
            return 'ok';
        });

        Assert::same($spy->renewCalls, 2);
        Assert::same($result, 'ok');
    }

    public function domainFailureIsCachedAndRethrownOnReplay(): never
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new \RuntimeException('insufficient funds');
        };

        try {
            $driver->execute('k', $op);
        } catch (\RuntimeException) {
            // first call: the domain failure is cached
        }

        Assert::same($calls, 1);

        Expect::exception(CachedDomainFailureException::class)->withMessage('insufficient funds');

        // replay rethrows the cached outcome (deterministic snapshot) without re-executing
        $driver->execute('k', $op);
    }

    public function cachedDomainFailureKeepsOriginalClass(): void
    {
        $driver = $this->driver();
        $op = static function (): never {
            throw new \DomainException('rejected');
        };

        try {
            $driver->execute('k', $op);
        } catch (\DomainException) {
        }

        try {
            $driver->execute('k', $op);
            Assert::fail('replay should rethrow');
        } catch (CachedDomainFailureException $replayed) {
            Assert::same($replayed->originalClass, \DomainException::class);
            Assert::same($replayed->getMessage(), 'rejected');
        }
    }

    public function replayableFailureRoundTripsExactType(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new PaymentDeclinedStub(402, 'card_declined');
        };

        try {
            $driver->execute('k', $op);
            Assert::fail('the first attempt must throw the domain failure');
        } catch (PaymentDeclinedStub) {
            // first call: the replayable failure is cached (class + message + payload)
        }

        try {
            $driver->execute('k', $op);
            Assert::fail('replay should rethrow the exact original type');
        } catch (PaymentDeclinedStub $replayed) {
            // The exact type is reconstructed from the payload — not a CachedDomainFailureException.
            Assert::same($replayed->declineCode, 402);
            Assert::same($replayed->reason, 'card_declined');
        }

        // The operation ran only once; the replay came from the cached snapshot.
        Assert::same($calls, 1);
    }

    public function vanishedReplayableClassFallsBackToSnapshot(): void
    {
        // Simulate a snapshot written by an earlier deploy: it carries a payload, but the failure class
        // no longer exists in this deploy. is_a() over a missing class is false → fall back, never fatal.
        $clock = new MutableClock();
        $manager = new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock);

        $acquired = $manager->acquire('k', 30);
        \assert($acquired instanceof Acquired);
        $blob = (string) (new PhpSerializer())->serialize([
            'class' => 'App\\Gone\\PaymentException',
            'message' => 'gone',
            'payload' => ['x' => 1],
        ]);
        $manager->complete('k', $acquired->token, false, $blob, 3600);

        $driver = new LeaseIdempotency($manager, new Pipeline(), lockTtl: 30, retentionTtl: 3600);

        try {
            $driver->execute('k', static fn(): string => 'never reached');
            Assert::fail('replay should rethrow the cached failure');
        } catch (CachedDomainFailureException $e) {
            Assert::same($e->originalClass, 'App\\Gone\\PaymentException');
            Assert::same($e->getMessage(), 'gone');
        }
    }

    public function infrastructureFailureAbortsAndAllowsRetry(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new \Error('transient');
        };

        foreach (['first', 'second'] as $_) {
            try {
                $driver->execute('k', $op);
            } catch (\Error) {
                // \Error → Infrastructure → abort() frees the key for a retry
            }
        }

        Assert::same($calls, 2);
    }

    public function bugFailureFreesTheKey(): void
    {
        $classifier = new DefaultFailureClassifier(bugExceptions: [\LogicException::class]);
        $driver = $this->driver(classifier: $classifier);
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new \LogicException('bug');
        };

        foreach (['first', 'second'] as $_) {
            try {
                $driver->execute('k', $op);
            } catch (\LogicException) {
                // Bug → error() deletes the record (not persisted), key is free again
            }
        }

        Assert::same($calls, 2);
    }

    public function uncacheableResultReleasesKeyAndReRuns(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): Uncacheable {
            ++$calls;
            return new Uncacheable('transient-' . $calls);
        };

        $first = $driver->execute('k', $op);
        $second = $driver->execute('k', $op);

        // Unwrapped value returned; not cached → key released → the operation runs again.
        Assert::same($first, 'transient-1');
        Assert::same($second, 'transient-2');
        Assert::same($calls, 2);
    }

    public function releasePolicyReRunsTheOperationAfterADomainFailure(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new \RuntimeException('listener blew up');
        };
        $options = new ExecuteOptions(failurePolicy: FailurePolicy::Release);

        foreach (['first', 'second'] as $_) {
            try {
                $driver->execute('k', $op, $options);
                Assert::fail('the domain failure must reach the caller');
            } catch (\RuntimeException $e) {
                // Release rethrows the ORIGINAL throwable, never a cached snapshot.
                Assert::same($e::class, \RuntimeException::class);
            }
        }

        // Nothing was completed, so the second call re-ran instead of replaying.
        Assert::same($calls, 2);
    }

    public function releasePolicyFreesTheKeyForABugFailureToo(): void
    {
        // Bug would normally reach error(); Release overrides the kind entirely, and error() equally
        // leaves the key free — what this pins is that the ORIGINAL throwable still reaches the caller.
        $classifier = new DefaultFailureClassifier(bugExceptions: [\LogicException::class]);
        $driver = $this->driver(classifier: $classifier);
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new \LogicException('bug');
        };
        $options = new ExecuteOptions(failurePolicy: FailurePolicy::Release);

        foreach (['first', 'second'] as $_) {
            try {
                $driver->execute('k', $op, $options);
            } catch (\LogicException) {
            }
        }

        Assert::same($calls, 2);
    }

    public function cachePolicyReplaysTheDomainFailure(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new \RuntimeException('insufficient funds');
        };
        $options = new ExecuteOptions(failurePolicy: FailurePolicy::Cache);

        try {
            $driver->execute('k', $op, $options);
        } catch (\RuntimeException) {
        }

        try {
            $driver->execute('k', $op, $options);
            Assert::fail('replay should rethrow the cached outcome');
        } catch (CachedDomainFailureException $replayed) {
            Assert::same($replayed->originalClass, \RuntimeException::class);
        }

        Assert::same($calls, 1);
    }

    public function perCallOptionsOverrideConfiguredTtls(): void
    {
        $manager = $this->capturingManager();
        $driver = new LeaseIdempotency($manager, new Pipeline(), lockTtl: 30, retentionTtl: 3600);

        $driver->execute('k', static fn(): string => 'x', new ExecuteOptions(lockTtl: 5, ttl: 99));

        Assert::same($manager->lockTtl, 5);
        Assert::same($manager->retentionTtl, 99);
    }

    public function absentOptionsFallBackToConfiguredTtls(): void
    {
        $manager = $this->capturingManager();
        $driver = new LeaseIdempotency($manager, new Pipeline(), lockTtl: 30, retentionTtl: 3600);

        // No options at all, then options whose fields are null — both fall back to the config defaults.
        $driver->execute('k', static fn(): string => 'x');
        Assert::same($manager->lockTtl, 30);
        Assert::same($manager->retentionTtl, 3600);

        $driver->execute('k2', static fn(): string => 'x', new ExecuteOptions());
        Assert::same($manager->lockTtl, 30);
        Assert::same($manager->retentionTtl, 3600);
    }

    /**
     * A lease manager that records the lock/retention TTLs it was asked to use, always granting the lease.
     */
    private function capturingManager(): LeaseManager
    {
        return new class implements LeaseManager {
            public ?int $lockTtl = null;
            public ?int $retentionTtl = null;

            public function acquire(string $key, int $lockTtl): AcquireResult
            {
                $this->lockTtl = $lockTtl;
                return new Acquired($key, 'token');
            }

            public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): void
            {
                $this->retentionTtl = $retentionTtl;
            }

            public function abort(string $key, string $token): void {}

            public function error(string $key, string $token): void {}

            public function renew(string $key, string $token, int $lockTtl): void {}
        };
    }

    // ----------------------------------------------------------------- lease lost at the terminal transition

    public function lostLeaseOnSuccessStillReturnsValue(): void
    {
        // complete() is CAS-rejected (lease taken over), but the operation already produced its value.
        $result = $this->lostLeaseDriver()->execute('k', static fn(): string => 'v');

        Assert::same($result, 'v');
    }

    public function lostLeaseOnFailureRethrowsOriginal(): void
    {
        $driver = $this->lostLeaseDriver();

        try {
            $driver->execute('k', static function (): never {
                throw new \RuntimeException('funds');
            });
            Assert::fail('the operation threw, so execute() must rethrow');
        } catch (LeaseLostException $e) {
            Assert::fail('the lost lease must not surface: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            // The original domain throwable reaches the caller, not the LeaseLostException wrapper.
            Assert::same($e->getMessage(), 'funds');
        }
    }

    public function lostLeaseOnUncacheableReturnsUnwrappedValue(): void
    {
        // abort() is CAS-rejected, but the transient value is still handed back unwrapped.
        $result = $this->lostLeaseDriver()->execute('k', static fn(): Uncacheable => new Uncacheable('transient'));

        Assert::same($result, 'transient');
    }

    public function lostLeaseIsReportedToLogger(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $this->lostLeaseDriver($logger)->execute('lost-key', static fn(): string => 'v');

        Assert::same(\count($logger->records), 1);
        Assert::same($logger->records[0]['level'], LogLevel::WARNING);
        Assert::same($logger->records[0]['context']['key'], 'lost-key');
    }

    /**
     * A driver whose storage grants acquire() but CAS-rejects every terminal transition — i.e. the lease
     * was taken over while the operation ran. {@see DefaultLeaseManager} turns that into a {@see LeaseLostException}.
     */
    private function lostLeaseDriver(?\Psr\Log\LoggerInterface $logger = null): LeaseIdempotency
    {
        $clock = new MutableClock();
        $classifier = new DefaultFailureClassifier();
        $storage = new class implements LeaseStorage {
            public function acquire(string $key, string $token, int $lockTtl): bool
            {
                return true;
            }

            public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): bool
            {
                return false;
            }

            public function abort(string $key, string $token): bool
            {
                return false;
            }

            public function error(string $key, string $token): bool
            {
                return false;
            }

            public function renew(string $key, string $token, int $lockTtl): bool
            {
                return false;
            }

            public function read(string $key): ?StoredEntry
            {
                return null;
            }
        };
        $manager = new DefaultLeaseManager($storage, $clock);
        $execution = new Pipeline(new ClassifierMiddleware($classifier));

        return new LeaseIdempotency($manager, $execution, lockTtl: 30, retentionTtl: 3600, classifier: $classifier, logger: $logger);
    }

    public function lockedKeyThrowsLockedException(): never
    {
        $clock = new MutableClock();
        $manager = new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock);
        $driver = new LeaseIdempotency($manager, new Pipeline(), lockTtl: 30, retentionTtl: 3600);

        // Occupy the key with an in-flight PROCESSING lease held by "someone else".
        $manager->acquire('k', 30);

        Expect::exception(LockedException::class);

        $driver->execute('k', static fn(): string => 'never reached');
    }

    // ----------------------------------------------------------------- AcquireRetry loop bound

    public function acquireRetryExhaustionThrows(): void
    {
        // The record keeps vanishing between the conditional insert and the conflict read, so every
        // acquire() resolves to AcquireRetry — the loop must give up at the configured limit (3).
        $storage = $this->alwaysRetryStorage();
        $driver = new LeaseIdempotency(
            new DefaultLeaseManager($storage, new MutableClock()),
            new Pipeline(),
        );

        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            return 'never reached';
        };

        try {
            $driver->execute('k', $op);
            Assert::fail('an exhausted AcquireRetry loop must throw');
        } catch (IdempotencyException $e) {
            Assert::string($e->getMessage())->contains('after 3 attempts');
        }

        // acquire() was attempted exactly acquireRetryLimit (default 3) times and the operation, which
        // never got a lease, never ran.
        Assert::same($storage->acquireCalls, 3);
        Assert::same($calls, 0);
    }

    public function acquireRetrySucceedsMidLoop(): void
    {
        // The first acquire() misses (AcquireRetry); the second wins the lease — the loop must retry
        // and then run the operation, returning its value.
        $storage = $this->retryOnceThenAcquireStorage();
        $driver = new LeaseIdempotency(
            new DefaultLeaseManager($storage, new MutableClock()),
            new Pipeline(),
        );

        $calls = 0;
        $result = $driver->execute('k', static function () use (&$calls): string {
            ++$calls;
            return 'value';
        });

        Assert::same($result, 'value');
        Assert::same($calls, 1);
        Assert::same($storage->acquireCalls, 2);
    }

    /**
     * A storage whose acquire() never succeeds and whose read() finds nothing — the manager maps that
     * to a perpetual {@see \Spiral\Idempotency\Lease\AcquireRetry}. Counts acquire() attempts.
     */
    private function alwaysRetryStorage(): LeaseStorage
    {
        return new class implements LeaseStorage {
            public int $acquireCalls = 0;

            public function acquire(string $key, string $token, int $lockTtl): bool
            {
                ++$this->acquireCalls;
                return false;
            }

            public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): bool
            {
                return false;
            }

            public function abort(string $key, string $token): bool
            {
                return false;
            }

            public function error(string $key, string $token): bool
            {
                return false;
            }

            public function renew(string $key, string $token, int $lockTtl): bool
            {
                return false;
            }

            public function read(string $key): ?StoredEntry
            {
                return null;
            }
        };
    }

    /**
     * A storage that reports AcquireRetry on the first acquire() (miss + vanished read) and then grants
     * the lease on the second — the positive exit of the retry loop.
     */
    private function retryOnceThenAcquireStorage(): LeaseStorage
    {
        return new class implements LeaseStorage {
            public int $acquireCalls = 0;

            public function acquire(string $key, string $token, int $lockTtl): bool
            {
                return ++$this->acquireCalls >= 2;
            }

            public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): bool
            {
                return true;
            }

            public function abort(string $key, string $token): bool
            {
                return true;
            }

            public function error(string $key, string $token): bool
            {
                return true;
            }

            public function renew(string $key, string $token, int $lockTtl): bool
            {
                return true;
            }

            public function read(string $key): ?StoredEntry
            {
                return null;
            }
        };
    }
}
