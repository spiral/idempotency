<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Key;

use Spiral\Idempotency\ArgumentKeyResolver;

/**
 * Default extractor: walks the path over array keys and object properties, then converts the leaf.
 *
 * Accepted leaves are scalars, {@see \Stringable} (domain ids such as UUID/ULID wrappers) and
 * {@see \BackedEnum} (its backing value). Anything else — an array, a plain object, null — yields null,
 * so the caller can report a misconfigured path instead of deduplicating on `"Array"` or `""`.
 *
 * @internal Bound to {@see ArgumentKeyResolver} by the bootloader; not part of the public API.
 */
final class DefaultArgumentKeyResolver implements ArgumentKeyResolver
{
    public function resolve(array $arguments, string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $cursor = $arguments;
        foreach (\explode('.', $path) as $segment) {
            if (\is_array($cursor) && \array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
                continue;
            }

            // isset() rather than property_exists(): a private/protected property is not readable from
            // here, and __get() on a magic accessor is only safe to trigger behind __isset().
            if (\is_object($cursor) && isset($cursor->{$segment})) {
                $cursor = $cursor->{$segment};
                continue;
            }

            return null;
        }

        return match (true) {
            $cursor instanceof \BackedEnum => (string) $cursor->value,
            \is_scalar($cursor), $cursor instanceof \Stringable => (string) $cursor,
            default => null,
        };
    }
}
