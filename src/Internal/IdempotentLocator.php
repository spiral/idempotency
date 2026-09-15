<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal;

use Spiral\Idempotency\Attribute\Idempotent;

/**
 * Shared {@see Idempotent} lookup for the declarative entry points (interceptor, event listener
 * factory): the method attribute first, then the class hierarchy of the CONCRETE class from the most
 * derived up. A method attribute wins over a class one, a subclass over its parents.
 *
 * The concrete class is passed in rather than taken from
 * {@see \ReflectionMethod::getDeclaringClass()}: for an entry point inherited from an abstract base
 * (a `JobHandler::handle()` forwarding to the subclass) the declaring class is the base, and the
 * attribute lives on the subclass.
 *
 * @internal
 */
final class IdempotentLocator
{
    /**
     * @param class-string|null $concrete class the callable was reached through; null skips the class
     *        walk (a closure or function has no owning hierarchy)
     */
    public static function locate(\ReflectionFunctionAbstract $reflection, ?string $concrete = null): ?Idempotent
    {
        $resolved = self::fromReflection($reflection);
        if ($resolved !== null || $concrete === null) {
            return $resolved;
        }

        $class = new \ReflectionClass($concrete);
        do {
            $resolved = self::fromReflection($class);
        } while ($resolved === null && ($class = $class->getParentClass()) !== false);

        return $resolved;
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionFunctionAbstract $reflection
     */
    public static function fromReflection(\ReflectionClass|\ReflectionFunctionAbstract $reflection): ?Idempotent
    {
        foreach ($reflection->getAttributes(Idempotent::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $instance = $attribute->newInstance();
            \assert($instance instanceof Idempotent);

            return $instance;
        }

        return null;
    }
}
