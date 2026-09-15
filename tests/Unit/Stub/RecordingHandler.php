<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Stub;

use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\HandlerInterface;

/**
 * Records every call and answers with its run number, so a skipped or repeated interception shows up in
 * the returned value as well as in {@see $calls}.
 */
final class RecordingHandler implements HandlerInterface
{
    /** @var list<CallContextInterface> */
    public array $calls = [];

    #[\Override]
    public function handle(CallContextInterface $context): mixed
    {
        $this->calls[] = $context;

        return 'run#' . \count($this->calls);
    }
}
