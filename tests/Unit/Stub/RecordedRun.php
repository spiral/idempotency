<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Stub;

use Spiral\Idempotency\ExecuteOptions;

/**
 * One `execute()` handed to {@see RecordingIdempotency}: the final key the caller composed, and the TTL
 * overrides it passed.
 */
final readonly class RecordedRun
{
    /**
     * @param non-empty-string $key
     */
    public function __construct(
        public string $key,
        public ?ExecuteOptions $options = null,
    ) {}
}
