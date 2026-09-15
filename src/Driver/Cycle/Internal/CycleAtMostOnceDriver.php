<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Query\InsertQuery;
use Cycle\Database\Query\OnConflict;
use Cycle\Database\Query\QueryParameters;
use Psr\Clock\ClockInterface;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProvider;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\Uncacheable;
use Spiral\Serializer\Serializer\PhpSerializer;
use Spiral\Serializer\SerializerInterface;

/**
 * AtMostOnce (dedup-guard) driver. The dedup marker is committed *before* the effect and is never
 * removed on any outcome, mirroring the inbox but with the opposite ordering:
 *
 *   1. `INSERT ... ON CONFLICT (key) DO NOTHING` commits immediately (the point of no return);
 *   2. on a fresh insert the effect runs fire-and-forget — a crash here loses the effect, the marker
 *      persists, so a retry is *refused* (not re-run). The effect therefore runs 0 or 1 times;
 *   3. a duplicate sees the marker and is refused: it returns `null` (fire-once) or, with
 *      {@see $cacheResult} on, the best-effort cached result.
 *
 * This is a dedup-guard, not an idempotency cache: only the effect (≤ 1) is guaranteed. The optional
 * result cache ({@see $cacheResult}) is written *after* the effect, NOT atomically with the marker, so
 * a crash between the two loses the cache but not the dedup — replay of the original result is therefore
 * best-effort only. Atomic marker + result is exactly the upgrade to {@see Guarantee::ExactlyOnce}.
 *
 * The marker must commit independently of the effect, so an ambient transaction on the connection is
 * rejected fail-fast — it would tie the marker to the outer commit/rollback and break "≤ once".
 *
 * @internal Built per storage alias by the factory; consumers resolve the configured storage from the
 *           {@see \Spiral\Idempotency\IdempotencyRegistry}. Not part of the public API.
 */
final readonly class CycleAtMostOnceDriver implements Idempotency, GuaranteeProvider
{
    private SerializerInterface $serializer;

    /**
     * @param \Closure(): DatabaseInterface $database lazy factory — resolved at execute() time to avoid
     *        a container cycle during interceptor-pipeline assembly
     * @param non-empty-string $table
     * @param bool $cacheResult best-effort store + replay of the operation result (default off: a
     *        duplicate gets `null`). Even when on, replay is not guaranteed (see class docblock).
     */
    public function __construct(
        private \Closure $database,
        private ClockInterface $clock,
        private ?string $connection = null,
        private string $table = 'idempotency_at_most_once',
        ?SerializerInterface $serializer = null,
        private bool $cacheResult = false,
    ) {
        $this->serializer = $serializer ?? new PhpSerializer();
    }

    public function guarantee(): Guarantee
    {
        return Guarantee::AtMostOnce;
    }

    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
    {
        // $options TTLs are intentionally ignored: the marker is terminal from the moment it commits
        // (no lease, no PROCESSING, no expiry). $ttl is reserved for a future retention/GC. So is
        // $failurePolicy: releasing the key after a failure would let the effect run twice, which is the
        // one thing this guarantee forbids.
        /** @var non-empty-string $key */
        $db = ($this->database)();

        // The marker must commit independently of the effect; an ambient transaction would tie it to the
        // outer commit/rollback and break the "≤ once" guarantee.
        if ($db->getDriver()->getTransactionLevel() !== 0) {
            throw new MisconfigurationException(
                'AtMostOnce requires autocommit: the dedup marker must commit before the effect runs, but '
                . 'an outer transaction is open on this connection.',
                'Run the operation outside an ambient transaction, or use an ExactlyOnce (inbox) storage '
                . 'if the effect must be transactional.',
            );
        }

        // Atomic conditional insert = the commit point. No takeover of an existing record (any record
        // means "already consumed").
        if ($this->runInsert($db, $key) !== 1) {
            return $this->replay($db, $key); // duplicate → fire-once null (or best-effort result)
        }

        // Marker committed. Fire-and-forget: on ANY outcome (success, throwable, crash) the marker
        // persists, so a retry is refused and the effect stays ≤ 1.
        $value = $operation(new AtMostOnceContext($key));
        if ($value instanceof Uncacheable) {
            $value = $value->value;
        }

        if ($this->cacheResult && $value !== null) {
            // Best-effort ONLY: a crash before this UPDATE loses the cache, not the dedup.
            $db->update(
                $this->table,
                ['result' => (string) $this->serializer->serialize($value)],
                ['key' => $key],
            )->run();
        }

        return $value;
    }

    /**
     * A duplicate: the effect already ran (or was lost). With the result cache off, "already processed"
     * is signalled with `null`; with it on, read the best-effort cached result (which may still be
     * `null` if the first caller never reached the store).
     */
    private function replay(DatabaseInterface $db, string $key): mixed
    {
        if (!$this->cacheResult) {
            return null;
        }

        $row = $db->select('result')->from($this->table)->where('key', $key)->run()->fetch();
        if (!\is_array($row) || $row['result'] === null) {
            return null;
        }

        return $this->serializer->unserialize((string) $row['result']);
    }

    /**
     * @param non-empty-string $key
     */
    private function runInsert(DatabaseInterface $db, string $key): int
    {
        $insert = $db->insert($this->table)
            ->columns('key', 'create_time', 'result')
            ->values([$key, $this->clock->now()->getTimestamp(), null])
            ->onConflict(OnConflict::target('key')->doNothing());

        return $this->compileAndExecute($db, $insert);
    }

    private function compileAndExecute(DatabaseInterface $db, InsertQuery $insert): int
    {
        $params = new QueryParameters();
        // sqlStatement() is the documented compile entrypoint; we need the affected-row count to tell a
        // fresh insert (1) from an ON CONFLICT skip (0). Relies on cycle/database >= 2.21.
        /** @psalm-suppress InternalMethod */
        $sql = $insert->sqlStatement($params);

        return $db->execute($sql, $params->getParameters());
    }
}
