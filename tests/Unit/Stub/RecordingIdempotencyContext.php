<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Stub;

use Spiral\Idempotency\IdempotencyContext;

/**
 * Records every renewal the operation asks for, with the `force` flag it passed — enough to assert both
 * how often a heartbeat fired and whether it bypassed the throttle.
 */
final class RecordingIdempotencyContext implements IdempotencyContext
{
    /** @var list<bool> */
    public array $renewals = [];

    /**
     * @param non-empty-string $key
     */
    public function __construct(private readonly string $key = 'k') {}

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }

    #[\Override]
    public function renew(bool $force = false): void
    {
        $this->renewals[] = $force;
    }
}
