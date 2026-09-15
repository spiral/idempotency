<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Stub;

use Spiral\Idempotency\IdempotencyContext;

/**
 * Hands the operation a fixed key; renewal is a no-op, as it is for every driver without a renewable
 * lock.
 */
final readonly class StubIdempotencyContext implements IdempotencyContext
{
    /**
     * @param non-empty-string $key
     */
    public function __construct(private string $key) {}

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }

    #[\Override]
    public function renew(bool $force = false): void {}
}
