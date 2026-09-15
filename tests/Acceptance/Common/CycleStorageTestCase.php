<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Common;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Schema;
use Cycle\Transaction\Internal\TransactionImpl;
use Spiral\Core\Container;
use Spiral\Idempotency\Bootloader\IdempotencyBootloader;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Driver\Cycle\CycleAtMostOnceConfig;
use Spiral\Idempotency\Driver\Cycle\CycleContext;
use Spiral\Idempotency\Driver\Cycle\CycleGarbageCollector;
use Spiral\Idempotency\Driver\Cycle\CycleInboxConfig;
use Spiral\Idempotency\Driver\Cycle\CycleLeaseConfig;
use Spiral\Idempotency\Driver\Cycle\CycleSchema;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleAtMostOnceDriver;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleInboxDriver;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleLeaseStorage;
use Spiral\Idempotency\Exception\LeaseLostException;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Lease\Acquired;
use Spiral\Idempotency\Lease\AlreadyCompleted;
use Spiral\Idempotency\Lease\LeaseState;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Lease\TokenFactory;
use Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Idempotency\Uncacheable;
use Spiral\Serializer\SerializerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;

/**
 * Lease (AtLeastOnce) + inbox (ExactlyOnce) storage and bootloader wiring, exercised against a real
 * connection. Each test takes a unique key from {@see DatabaseTestCase::key()} so its rows never
 * collide with another test's in the shared, never-cleaned tables.
 */
#[Covers(CycleLeaseStorage::class)]
#[Covers(CycleInboxDriver::class)]
#[Covers(CycleAtMostOnceDriver::class)]
#[Covers(CycleGarbageCollector::class)]
#[Covers(IdempotencyBootloader::class)]
#[Covers(IdempotencyConfig::class)]
abstract class CycleStorageTestCase extends DatabaseTestCase
{
    private static int $gcSequence = 0;

    // ----------------------------------------------------------------- lease storage

    public function acquireInsertsProcessingRecord(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());

        Assert::true($storage->acquire($key, 'tok', 30));

        $entry = $storage->read($key);
        Assert::same($entry?->state, LeaseState::Processing);
        Assert::same($entry?->token, 'tok');
    }

    public function secondAcquireConflicts(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());
        $storage->acquire($key, 'tok', 30);

        Assert::false($storage->acquire($key, 'other', 30));
    }

    public function completeStoresResultAndClearsToken(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->complete($key, 'tok', true, 'payload', 3600));

        $entry = $storage->read($key);
        Assert::same($entry?->state, LeaseState::Completed);
        Assert::same($entry?->result, 'payload');
        Assert::true($entry?->success);
        Assert::null($entry?->token);
    }

    public function completeWithWrongTokenFails(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());
        $storage->acquire($key, 'tok', 30);

        Assert::false($storage->complete($key, 'wrong', true, 'x', 3600));
    }

    public function abortDeletesRecord(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->abort($key, 'tok'));
        Assert::null($storage->read($key));
    }

    public function expiredRecordIsTakenOverOnAcquire(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);
        $storage->acquire($key, 'old', 10);

        $clock->advance(11);

        Assert::true($storage->acquire($key, 'new', 10));
        Assert::same($storage->read($key)?->token, 'new');
    }

    public function readTreatsExpiredAsAbsent(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);
        $storage->acquire($key, 'tok', 10);

        $clock->advance(11);

        Assert::null($storage->read($key));
    }

    public function renewExtendsExpiry(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);
        $storage->acquire($key, 'tok', 10);

        $clock->advance(8);
        Assert::true($storage->renew($key, 'tok', 10));
        $clock->advance(5);

        Assert::same($storage->read($key)?->state, LeaseState::Processing);
    }

    public function renewIsNoOpSafeWithinSameSecond(): void
    {
        // MySQL rowCount() reports *changed* rows: renewing to the same expire_time (clock not advanced,
        // same TTL) changes nothing and returns 0 — yet we are still the owner, so renew must succeed.
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->renew($key, 'tok', 30));  // no-op update, still owned
        Assert::false($storage->renew($key, 'other', 30)); // a non-owner token still fails
    }

    public function managerFlowAcquireCompleteReplay(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $manager = new DefaultLeaseManager(new CycleLeaseStorage($this->db(), $clock), $clock);

        $acquired = $manager->acquire($key, 30);
        Assert::instanceOf($acquired, Acquired::class);

        $manager->complete($key, $acquired->token, true, 'cached', 3600);

        $replay = $manager->acquire($key, 30);
        Assert::instanceOf($replay, AlreadyCompleted::class);
        Assert::same($replay->result, 'cached');
    }

    public function takeoverThenOriginalOwnerCompletes(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);

        // The original owner ("old") runs through the LeaseIdempotency handler; its operation takes long
        // enough for the lock TTL to lapse and a second worker ("new") to take the key over mid-flight.
        $oldTokens = new class implements TokenFactory {
            public function create(): string
            {
                return 'old';
            }
        };
        $handler = new LeaseIdempotency(
            new DefaultLeaseManager($storage, $clock, $oldTokens),
            new Pipeline(new ClassifierMiddleware(new DefaultFailureClassifier())),
        );

        $result = $handler->execute($key, function () use ($storage, $clock, $key): string {
            $clock->advance(11); // lock TTL (10s) lapses
            Assert::true($storage->acquire($key, 'new', 30)); // "new" takes over the expired lease
            return 'v';
        }, new ExecuteOptions(lockTtl: 10));

        // The operation's value reaches the caller even though complete() was CAS-rejected...
        Assert::same($result, 'v');
        // ...and the stored record still belongs to the new owner — the loss did not overwrite it.
        $entry = $storage->read($key);
        Assert::same($entry?->token, 'new');
        Assert::same($entry?->state, LeaseState::Processing);

        // The manager's strict contract is intact: a stale owner completing directly still throws.
        $staleManager = new DefaultLeaseManager($storage, $clock, $oldTokens);
        try {
            $staleManager->complete($key, 'old', true, 'ignored', 3600);
            Assert::fail('the stale owner must be rejected by the manager CAS');
        } catch (LeaseLostException) {
            // expected — only the handler forgives the loss, the manager signals it
        }

        // The rejected direct complete left the new owner's record untouched.
        Assert::same($storage->read($key)?->token, 'new');
    }

    public function managerReportsLockedWhileProcessing(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $manager = new DefaultLeaseManager(new CycleLeaseStorage($this->db(), $clock), $clock);
        $manager->acquire($key, 30);

        Assert::instanceOf($manager->acquire($key, 30), Locked::class);
    }

    // ----------------------------------------------------------------- inbox driver

    public function inboxExecutesOnceAndReturnsResult(): void
    {
        $key = $this->key();

        $result = $this->inboxDriver()->execute($key, static fn(IdempotencyContext $c): string => 'ok:' . $c->getKey());

        Assert::same($result, 'ok:' . $key);
    }

    public function inboxDeduplicatesAndReplaysCachedResult(): void
    {
        $key = $this->key();
        $driver = $this->inboxDriver();
        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            return 'value';
        };

        Assert::same($driver->execute($key, $op), 'value');
        Assert::same($driver->execute($key, $op), 'value');
        Assert::same($calls, 1);
    }

    public function inboxSideEffectAndDedupCommitTogether(): void
    {
        $key = $this->key();
        $driver = $this->inboxDriver();
        $op = function (IdempotencyContext $c) use ($key): string {
            \assert($c instanceof CycleContext);
            $c->database()->insert('ledger')->values(['note' => $key])->run();
            return 'done';
        };

        $driver->execute($key, $op);
        $driver->execute($key, $op); // dedup — must NOT write a second ledger row

        Assert::same($this->ledgerCount($key), 1);
    }

    public function inboxRollbackOnFailureLeavesNoTraceAndAllowsRetry(): void
    {
        $key = $this->key();
        $driver = $this->inboxDriver();
        $attempt = 0;
        $op = function (IdempotencyContext $c) use (&$attempt, $key): string {
            ++$attempt;
            \assert($c instanceof CycleContext);
            $c->database()->insert('ledger')->values(['note' => $key])->run();
            if ($attempt === 1) {
                throw new \RuntimeException('boom'); // rolls back the inbox row AND the ledger insert
            }
            return 'done';
        };

        try {
            $driver->execute($key, $op);
        } catch (\RuntimeException) {
        }

        // First attempt fully rolled back: no inbox row, no ledger row.
        Assert::same($this->ledgerCount($key), 0);

        // Retry succeeds and commits exactly one effect.
        Assert::same($driver->execute($key, $op), 'done');
        Assert::same($this->ledgerCount($key), 1);
        Assert::same($attempt, 2);
    }

    public function inboxUnwrapsUncacheableAndCommits(): void
    {
        $key = $this->key();
        $driver = $this->inboxDriver();
        $calls = 0;
        $op = function (IdempotencyContext $c) use (&$calls, $key): Uncacheable {
            ++$calls;
            \assert($c instanceof CycleContext);
            $c->database()->insert('ledger')->values(['note' => $key])->run();
            return new Uncacheable('r1');
        };

        // First call: the inbox unwraps the Uncacheable to its value and COMMITs the side-effect with
        // the dedup row (the ledger insert survives the transaction).
        Assert::same($driver->execute($key, $op), 'r1');
        Assert::same($this->ledgerCount($key), 1);

        // Asymmetry with the lease driver: an Uncacheable does NOT re-run through the inbox. The
        // side-effect is already committed, so the second call replays the cached 'r1' and the
        // operation body never runs again.
        Assert::same($driver->execute($key, $op), 'r1');
        Assert::same($this->ledgerCount($key), 1);
        Assert::same($calls, 1);
    }

    public function inboxIgnoresTheReleaseFailurePolicy(): void
    {
        // Same asymmetry as Uncacheable: FailurePolicy is a lease concept. A committed inbox row is
        // terminal, so Release cannot make a second call re-run the side-effect.
        $key = $this->key();
        $driver = $this->inboxDriver();
        $options = new ExecuteOptions(failurePolicy: FailurePolicy::Release);
        $calls = 0;
        $op = function (IdempotencyContext $c) use (&$calls, $key): string {
            ++$calls;
            \assert($c instanceof CycleContext);
            $c->database()->insert('ledger')->values(['note' => $key])->run();
            return 'r1';
        };

        Assert::same($driver->execute($key, $op, $options), 'r1');
        Assert::same($driver->execute($key, $op, $options), 'r1');
        Assert::same($this->ledgerCount($key), 1);
        Assert::same($calls, 1);
    }

    // ----------------------------------------------------------------- at-most-once (dedup-guard) driver

    public function atMostOnceRunsEffectOnce(): void
    {
        $key = $this->key();
        $driver = $this->atMostOnceDriver();
        $calls = 0;
        $op = function (IdempotencyContext $c) use (&$calls, $key): string {
            ++$calls;
            $this->db()->insert('ledger')->values(['note' => $key])->run();
            return 'v:' . $c->getKey();
        };

        // First run: the effect runs and the value reaches the caller.
        Assert::same($driver->execute($key, $op), 'v:' . $key);
        // Duplicate: refused (not re-run). The default (no result cache) signals "already processed"
        // with null, and the effect ran exactly once.
        Assert::null($driver->execute($key, $op));
        Assert::same($this->ledgerCount($key), 1);
        Assert::same($calls, 1);
    }

    public function atMostOnceDoesNotReRunAfterFailure(): void
    {
        // The contract test: the marker commits BEFORE the effect and is never removed, so a crash in
        // the effect loses it (≤ once) and a retry is refused rather than re-running the partial effect.
        $key = $this->key();
        $driver = $this->atMostOnceDriver();
        $calls = 0;
        $op = function () use (&$calls, $key): string {
            ++$calls;
            $this->db()->insert('ledger')->values(['note' => $key])->run();
            throw new \RuntimeException('boom');
        };

        // The throwable propagates to the caller (fire-and-forget: the driver does not swallow it)...
        try {
            $driver->execute($key, $op);
            Assert::fail('the operation must propagate its RuntimeException');
        } catch (\RuntimeException) {
            // expected
        }

        // ...but the marker persists, so the retry is refused: no re-run, exactly one partial effect.
        Assert::null($driver->execute($key, $op));
        Assert::same($this->ledgerCount($key), 1);
        Assert::same($calls, 1);
    }

    public function atMostOnceIgnoresTheReleaseFailurePolicy(): void
    {
        // Honouring Release here would let the effect run twice — the one thing "≤ once" forbids.
        $key = $this->key();
        $driver = $this->atMostOnceDriver();
        $options = new ExecuteOptions(failurePolicy: FailurePolicy::Release);
        $calls = 0;
        $op = function () use (&$calls, $key): string {
            ++$calls;
            $this->db()->insert('ledger')->values(['note' => $key])->run();
            throw new \RuntimeException('boom');
        };

        try {
            $driver->execute($key, $op, $options);
            Assert::fail('the operation must propagate its RuntimeException');
        } catch (\RuntimeException) {
        }

        // The marker stands despite Release: the retry is refused, not re-run.
        Assert::null($driver->execute($key, $op, $options));
        Assert::same($this->ledgerCount($key), 1);
        Assert::same($calls, 1);
    }

    public function atMostOnceRejectsAmbientTransaction(): never
    {
        // An open outer transaction would tie the marker to the outer commit/rollback and break "≤ once".
        $key = $this->key();
        $driver = $this->atMostOnceDriver();

        Expect::exception(MisconfigurationException::class)->withMessageContaining('autocommit');

        $this->db()->begin();
        try {
            $driver->execute($key, static fn(): string => 'never');
        } finally {
            $this->db()->rollback();
        }
    }

    public function atMostOnceBestEffortResultReplaysWhenCached(): void
    {
        $key = $this->key();
        $driver = $this->atMostOnceDriver(cacheResult: true);
        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            return 'v';
        };

        // With the result cache on, the first run stores the value...
        Assert::same($driver->execute($key, $op), 'v');
        // ...and the duplicate replays it from the cache without re-running the operation.
        Assert::same($driver->execute($key, $op), 'v');
        Assert::same($calls, 1);
    }

    // ----------------------------------------------------------------- bootloader wiring

    public function wiresLeaseAndInboxDriversFromConfig(): void
    {
        $leaseKey = $this->key('lease');
        $inboxKey = $this->key('inbox');

        $config = new IdempotencyConfig([
            'default' => 'notifications',
            'storages' => [
                'notifications' => new CycleLeaseConfig(table: 'idempotency'),
                'orders' => new CycleInboxConfig(table: 'inbox'),
            ],
        ]);

        $registry = $this->buildRegistry($config);

        $calls = 0;
        $op = static function (IdempotencyContext $c) use (&$calls): string {
            ++$calls;
            return 'r:' . $c->getKey();
        };

        // Lease (AtLeastOnce) alias: executes once, replays the cache.
        Assert::same($registry->get('notifications')->execute($leaseKey, $op), 'r:' . $leaseKey);
        Assert::same($registry->get('notifications')->execute($leaseKey, $op), 'r:' . $leaseKey);
        // Inbox (ExactlyOnce) alias: dedup + replay.
        Assert::same($registry->get('orders')->execute($inboxKey, $op), 'r:' . $inboxKey);
        Assert::same($registry->get('orders')->execute($inboxKey, $op), 'r:' . $inboxKey);

        Assert::same($calls, 2);
    }

    public function failsFastWhenAliasDeclaresUnbackedGuarantee(): never
    {
        $config = new IdempotencyConfig([
            'storages' => [
                // CycleLease can only provide AtLeastOnce — declaring ExactlyOnce is a misconfig.
                'orders' => new CycleLeaseConfig(guarantee: Guarantee::ExactlyOnce),
            ],
        ]);

        Expect::exception(MisconfigurationException::class)->withMessageContaining('ExactlyOnce');

        $this->buildRegistry($config);
    }

    public function usesContainerBoundSerializerForResultBlob(): void
    {
        $key = $this->key('serializer');

        // A JSON serializer bound in the container must be used by the driver instead of the PhpSerializer
        // default (7c). Proof is two-fold: the round-trip replays the exact value, AND the persisted blob
        // is JSON, not a PHP-serialized string (which would begin with 's:' for a string payload).
        $json = new class implements SerializerInterface {
            public function serialize(mixed $payload): string
            {
                return \json_encode($payload, \JSON_THROW_ON_ERROR);
            }

            public function unserialize(string|\Stringable $payload, string|object|null $type = null): mixed
            {
                return \json_decode((string) $payload, true, 512, \JSON_THROW_ON_ERROR);
            }
        };

        $config = new IdempotencyConfig([
            'storages' => ['notifications' => new CycleLeaseConfig(table: 'idempotency')],
        ]);
        $registry = $this->buildRegistry($config, $json);

        $calls = 0;
        $op = static function () use (&$calls): array {
            ++$calls;
            return ['value' => 'v'];
        };

        Assert::same($registry->get('notifications')->execute($key, $op), ['value' => 'v']);
        Assert::same($registry->get('notifications')->execute($key, $op), ['value' => 'v']); // replay
        Assert::same($calls, 1);

        // The stored blob is JSON produced by the injected serializer, not a PHP-serialized payload.
        $row = $this->db()->select('result')->from('idempotency')->where('key', $key)->run()->fetch();
        Assert::true(\is_array($row));
        Assert::same((string) $row['result'], '{"value":"v"}');
    }

    // ----------------------------------------------------------------- garbage collection

    // GC deletes by TIME across the WHOLE table, so — unlike the key-isolated tests above — a GC test
    // must NEVER touch the shared tables. Each test declares its own dedicated table(s) via a unique
    // name (see gcTable()) so its sweep cannot clobber another test's rows.

    public function gcDeletesExpiredLeaseRowsKeepsLive(): void
    {
        $table = $this->gcTable('lease');
        CycleSchema::declare($this->db(), $table);

        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock, $table);
        Assert::true($storage->acquire('live', 'tok-live', 1000));
        Assert::true($storage->acquire('dead', 'tok-dead', 10));

        $clock->advance(11); // the 10s lock expires; the 1000s one is still live

        $config = new IdempotencyConfig(['storages' => ['gc' => new CycleLeaseConfig(table: $table)]]);
        $gc = new CycleGarbageCollector($config, $this->manager(), $clock);

        Assert::same($gc->collect(), ['gc' => 1]);
        Assert::same($storage->read('live')?->token, 'tok-live'); // live row survives
        Assert::null($storage->read('dead'));                     // expired row swept
    }

    public function gcSkipsInboxWithoutRetention(): void
    {
        $table = $this->gcTable('inbox');
        CycleSchema::declareInbox($this->db(), $table);

        $key = $this->key();
        $clock = new MutableClock();
        $this->db()->insert($table)
            ->values(['key' => $key, 'create_time' => $clock->now()->getTimestamp(), 'result' => null])
            ->run();

        // retentionTtl null (default) => kept forever, GC skips this storage entirely.
        $config = new IdempotencyConfig(['storages' => ['gc' => new CycleInboxConfig(table: $table)]]);
        $gc = new CycleGarbageCollector($config, $this->manager(), $clock);

        Assert::same($gc->collect(), []);                    // alias omitted (not swept)
        Assert::same($this->tableRowCount($table, $key), 1); // row survives
    }

    public function gcDeletesInboxRowsPastRetention(): void
    {
        $table = $this->gcTable('inbox');
        CycleSchema::declareInbox($this->db(), $table);

        $clock = new MutableClock();
        $old = $this->key('old');
        $this->db()->insert($table)
            ->values(['key' => $old, 'create_time' => $clock->now()->getTimestamp(), 'result' => null])
            ->run();

        $clock->advance(100);

        $fresh = $this->key('fresh');
        $this->db()->insert($table)
            ->values(['key' => $fresh, 'create_time' => $clock->now()->getTimestamp(), 'result' => null])
            ->run();

        $config = new IdempotencyConfig(['storages' => ['gc' => new CycleInboxConfig(table: $table, retentionTtl: 60)]]);
        $gc = new CycleGarbageCollector($config, $this->manager(), $clock);

        Assert::same($gc->collect()['gc'], 1);
        Assert::same($this->tableRowCount($table, $old), 0);   // age 100 > 60 => deleted
        Assert::same($this->tableRowCount($table, $fresh), 1); // age 0 < 60 => survives
    }

    public function gcDeletesAtMostOnceRowsPastRetention(): void
    {
        $table = $this->gcTable('amo');
        CycleSchema::declareAtMostOnce($this->db(), $table);

        $clock = new MutableClock();
        $old = $this->key('old');
        $this->db()->insert($table)
            ->values(['key' => $old, 'create_time' => $clock->now()->getTimestamp(), 'result' => null])
            ->run();

        $clock->advance(100);

        $fresh = $this->key('fresh');
        $this->db()->insert($table)
            ->values(['key' => $fresh, 'create_time' => $clock->now()->getTimestamp(), 'result' => null])
            ->run();

        $config = new IdempotencyConfig(['storages' => ['gc' => new CycleAtMostOnceConfig(table: $table, retentionTtl: 60)]]);
        $gc = new CycleGarbageCollector($config, $this->manager(), $clock);

        Assert::same($gc->collect()['gc'], 1);
        Assert::same($this->tableRowCount($table, $old), 0);   // age 100 > 60 => deleted
        Assert::same($this->tableRowCount($table, $fresh), 1); // age 0 < 60 => survives
    }

    public function gcReturnsPerAliasCounts(): void
    {
        $leaseTable = $this->gcTable('lease');
        $inboxTable = $this->gcTable('inbox');
        CycleSchema::declare($this->db(), $leaseTable);
        CycleSchema::declareInbox($this->db(), $inboxTable);

        $clock = new MutableClock();

        $lease = new CycleLeaseStorage($this->db(), $clock, $leaseTable);
        Assert::true($lease->acquire('dead', 'td', 10));
        Assert::true($lease->acquire('live', 'tl', 1000));

        $this->db()->insert($inboxTable)
            ->values(['key' => 'old', 'create_time' => $clock->now()->getTimestamp(), 'result' => null])
            ->run();

        $clock->advance(11); // dead lease (10) expires; 'old' inbox row is now 11s old

        $this->db()->insert($inboxTable)
            ->values(['key' => 'new', 'create_time' => $clock->now()->getTimestamp(), 'result' => null])
            ->run();

        $config = new IdempotencyConfig([
            'storages' => [
                'leases' => new CycleLeaseConfig(table: $leaseTable),
                'inbox' => new CycleInboxConfig(table: $inboxTable, retentionTtl: 5),
            ],
        ]);
        $gc = new CycleGarbageCollector($config, $this->manager(), $clock);

        // Lease: 'dead' swept, 'live' kept. Inbox: 'old' (age 11 > 5) swept, 'new' (age 0) kept.
        Assert::same($gc->collect(), ['leases' => 1, 'inbox' => 1]);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * A unique, valid table identifier for a GC test's own dedicated table (never the shared tables).
     *
     * @return non-empty-string
     */
    private function gcTable(string $prefix): string
    {
        return 'gc_' . $prefix . '_' . (++self::$gcSequence);
    }

    private function tableRowCount(string $table, string $key): int
    {
        return (int) $this->db()->select()->from($table)->where('key', $key)->count();
    }

    private function inboxDriver(): CycleInboxDriver
    {
        $manager = $this->manager();
        $transaction = new TransactionImpl(new ORM(new Factory($manager), new Schema([])), $manager);

        return new CycleInboxDriver(static fn(): TransactionImpl => $transaction, new MutableClock());
    }

    private function atMostOnceDriver(bool $cacheResult = false): CycleAtMostOnceDriver
    {
        return new CycleAtMostOnceDriver(
            fn(): DatabaseInterface => $this->db(),
            new MutableClock(),
            table: 'idempotency_at_most_once',
            cacheResult: $cacheResult,
        );
    }

    private function buildRegistry(IdempotencyConfig $config, ?SerializerInterface $serializer = null): IdempotencyRegistry
    {
        $manager = $this->manager();

        // The bootloader resolves storage factories through the container, so the Cycle factories
        // inject the database provider / ORM themselves — bind them and hand the container in.
        $container = new Container();
        $container->bindSingleton(DatabaseProviderInterface::class, $manager);
        $container->bindSingleton(ORMInterface::class, new ORM(new Factory($manager), new Schema([])));
        if ($serializer !== null) {
            // The bootloader defers to an application-provided SerializerInterface over its PhpSerializer default.
            $container->bindSingleton(SerializerInterface::class, $serializer);
        }

        return (new IdempotencyBootloader())->initRegistry(
            $config,
            $container,
            $container,
            new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
        );
    }

    private function ledgerCount(string $note): int
    {
        return (int) $this->db()->select()->from('ledger')->where('note', $note)->count();
    }
}
