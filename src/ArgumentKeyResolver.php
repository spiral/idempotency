<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Extracts raw key material from a call's arguments by a dot-notation path, as written in
 * `#[Idempotent(key: '...')]`.
 *
 * Only extraction: the returned string is fed to {@see KeyResolver}, which normalizes, composes the
 * hierarchy and hashes. Transports that read a key out of the payload resolve it through this service
 * rather than walking the arguments themselves.
 *
 * @api
 */
interface ArgumentKeyResolver
{
    /**
     * @param array<array-key, mixed> $arguments call arguments to walk
     * @param string $path dot-notation path over arrays and object properties
     * @return string|null null when the path leads nowhere, or to a value with no meaningful string form
     *         (array, plain object, null, bool); an empty string is a resolved but blank value, which
     *         {@see KeyResolver} rejects as unusable material
     */
    public function resolve(array $arguments, string $path): ?string;
}
