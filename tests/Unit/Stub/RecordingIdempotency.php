<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Stub;

use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Idempotency;

/**
 * Records every run and then executes the operation — never deduplicates, so a test sees each call
 * instead of a replay. Register one instance per storage alias to assert which alias a caller resolved.
 */
final class RecordingIdempotency implements Idempotency
{
    /** @var list<RecordedRun> */
    public array $calls = [];

    #[\Override]
    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
    {
        $this->calls[] = new RecordedRun($key, $options);

        return $operation(new StubIdempotencyContext($key));
    }

    /**
     * @return list<non-empty-string>
     */
    public function keys(): array
    {
        return \array_map(static fn(RecordedRun $run): string => $run->key, $this->calls);
    }
}
