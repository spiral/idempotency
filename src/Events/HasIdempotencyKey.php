<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Events;

/**
 * An event that carries its own stable identity, used as key material by
 * {@see IdempotentListenerFactory} when the `#[Idempotent]` attribute states no `key` arg-path.
 *
 * The natural fit for an event republished by a durable producer (an outbox row, a broker
 * redelivery): the identity must be the one the producer preserves across re-publications of the SAME
 * logical event — an outbox `message_id`, not a value regenerated per dispatch. A freshly generated id
 * makes every redelivery look like a new event and silently disables deduplication.
 *
 * @api
 */
interface HasIdempotencyKey
{
    /**
     * Key material for the event — deterministic across every dispatch of the same logical event. The
     * listener identity is mixed in separately as the key scope, so several listeners of one event do
     * not share a key.
     *
     * @return non-empty-string
     */
    public function idempotencyKey(): string;
}
