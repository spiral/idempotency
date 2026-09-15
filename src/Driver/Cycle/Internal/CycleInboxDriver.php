<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Query\InsertQuery;
use Cycle\Database\Query\OnConflict;
use Cycle\Database\Query\QueryParameters;
use Cycle\ORM\EntityManagerInterface;
use Cycle\Transaction\FlushMode;
use Cycle\Transaction\Transaction;
use Cycle\Transaction\TransactionMode;
use Psr\Clock\ClockInterface;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProvider;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\Uncacheable;
use Spiral\Serializer\Serializer\PhpSerializer;
use Spiral\Serializer\SerializerInterface;

/**
 * ExactlyOnce-effect driver. A single DB transaction (opened by {@see Transaction::transact()}) carries
 * both the dedup `INSERT ... ON CONFLICT (key) DO NOTHING` and the side-effect:
 *
 *   - crash before COMMIT → ROLLBACK → nothing committed → clean retry;
 *   - crash after COMMIT  → inbox row present → retry sees the conflict → skip. No window.
 *
 * The operation receives a {@see InboxContext} exposing the transaction's Entity Manager and DBAL
 * connection, so its side-effect commits atomically with the dedup record. {@see TransactionMode} /
 * {@see FlushMode} (from config) control how the scoped Entity Manager participates.
 *
 * @internal Built per storage alias by the bootloader; consumers resolve the configured storage
 *           from the {@see \Spiral\Idempotency\IdempotencyRegistry}. Not part of the public API.
 */
final readonly class CycleInboxDriver implements Idempotency, GuaranteeProvider
{
    private SerializerInterface $serializer;

    /**
     * @param \Closure(): Transaction $transaction lazy factory — resolved at execute() time, after the
     *        ORM is built, to avoid a container cycle during interceptor-pipeline assembly
     * @param string|null $connection DBAL connection name (or entity class); null = default
     * @param non-empty-string $table
     */
    public function __construct(
        private \Closure $transaction,
        private ClockInterface $clock,
        private ?string $connection = null,
        private string $table = 'inbox',
        ?SerializerInterface $serializer = null,
        private TransactionMode $transactionMode = TransactionMode::Exclusive,
        private FlushMode $flushMode = FlushMode::BeforeCommit,
    ) {
        $this->serializer = $serializer ?? new PhpSerializer();
    }

    public function guarantee(): Guarantee
    {
        return Guarantee::ExactlyOnce;
    }

    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
    {
        // $options->lockTtl is intentionally ignored: an inbox enforces mutual exclusion via the row
        // lock of the in-progress INSERT, not a time-bound lease. $options->ttl is reserved for a future
        // inbox retention/GC (rows are currently kept indefinitely). $options->failurePolicy is a no-op
        // as well — a throwing operation rolls the whole transaction back, dedup row included, which is
        // already Release; a committed one cannot be undone, so Cache has nothing to cache either.
        /** @var non-empty-string $key */
        $transaction = ($this->transaction)();
        \assert($transaction instanceof Transaction);

        return $transaction->transact(
            fn(EntityManagerInterface $em, DatabaseInterface $db): mixed => $this->run($db, $em, $key, $operation),
            $this->connection ?: null,
            $this->transactionMode,
            $this->flushMode,
        );
    }

    /**
     * @param non-empty-string $key
     */
    private function run(DatabaseInterface $db, EntityManagerInterface $em, string $key, \Closure $operation): mixed
    {
        $insert = $db->insert($this->table)
            ->columns('key', 'create_time', 'result')
            ->values([$key, $this->clock->now()->getTimestamp(), null])
            ->onConflict(OnConflict::target('key')->doNothing());

        if ($this->runInsert($db, $insert) !== 1) {
            // Already processed — replay the cached result (dedup is the primary purpose; the result
            // is an optional cache).
            return $this->readResult($db, $key);
        }

        // First time: run the side-effect inside this same transaction via the bound context.
        $value = $operation(new InboxContext($key, $db, $em));

        // An Uncacheable marker ("safe to re-run") is not applicable here: the side-effect is already
        // in this transaction and will commit. Unwrap and store/return the value like any other.
        if ($value instanceof Uncacheable) {
            $value = $value->value;
        }

        if ($value !== null) {
            $db->update(
                $this->table,
                ['result' => (string) $this->serializer->serialize($value)],
                ['key' => $key],
            )->run();
        }

        return $value;
    }

    private function readResult(DatabaseInterface $db, string $key): mixed
    {
        $row = $db->select('result')->from($this->table)->where('key', $key)->run()->fetch();
        if (!\is_array($row) || $row['result'] === null) {
            return null;
        }

        return $this->serializer->unserialize((string) $row['result']);
    }

    private function runInsert(DatabaseInterface $db, InsertQuery $insert): int
    {
        $params = new QueryParameters();
        // sqlStatement() is the documented compile entrypoint; we need the affected-row count to
        // tell a fresh insert from an ON CONFLICT skip.
        /** @psalm-suppress InternalMethod */
        $sql = $insert->sqlStatement($params);

        return $db->execute($sql, $params->getParameters());
    }
}
