<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Key;

use Spiral\Idempotency\Internal\Key\DefaultArgumentKeyResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

final class StringableId implements \Stringable
{
    public function __construct(private readonly string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

enum StringBackedKind: string
{
    case Charge = 'charge';
}

enum IntBackedKind: int
{
    case Charge = 7;
}

enum PureKind
{
    case Charge;
}

final class Payload
{
    public function __construct(
        public readonly mixed $id,
        private readonly string $secret = 'hidden',
    ) {}
}

#[Test]
#[Covers(DefaultArgumentKeyResolver::class)]
final class DefaultArgumentKeyResolverTest
{
    public function resolvesTopLevelScalarArgument(): void
    {
        Assert::same($this->resolve(['key' => 'abc'], 'key'), 'abc');
    }

    public function walksNestedArrays(): void
    {
        Assert::same($this->resolve(['payload' => ['order' => ['id' => 'o-1']]], 'payload.order.id'), 'o-1');
    }

    public function walksPublicObjectProperties(): void
    {
        Assert::same($this->resolve(['payload' => new Payload('o-1')], 'payload.id'), 'o-1');
    }

    public function castsStringableLeaf(): void
    {
        Assert::same($this->resolve(['payload' => new Payload(new StringableId('uid-7'))], 'payload.id'), 'uid-7');
    }

    public function readsBackingValueOfStringEnum(): void
    {
        Assert::same($this->resolve(['kind' => StringBackedKind::Charge], 'kind'), 'charge');
    }

    public function readsBackingValueOfIntEnum(): void
    {
        Assert::same($this->resolve(['kind' => IntBackedKind::Charge], 'kind'), '7');
    }

    public function castsNonStringScalars(): void
    {
        Assert::same($this->resolve(['n' => 42], 'n'), '42');
        Assert::same($this->resolve(['n' => 1.5], 'n'), '1.5');
    }

    public function rejectsBooleanLeaf(): void
    {
        Assert::null($this->resolve(['flag' => true], 'flag'));
        Assert::null($this->resolve(['flag' => false], 'flag'));
    }

    public function rejectsPureEnumLeaf(): void
    {
        Assert::null($this->resolve(['kind' => PureKind::Charge], 'kind'));
    }

    public function rejectsArrayLeaf(): void
    {
        Assert::null($this->resolve(['payload' => ['id' => 'o-1']], 'payload'));
    }

    public function rejectsObjectLeafWithoutStringForm(): void
    {
        Assert::null($this->resolve(['payload' => new Payload('o-1')], 'payload'));
    }

    public function rejectsNullLeaf(): void
    {
        Assert::null($this->resolve(['payload' => new Payload(null)], 'payload.id'));
    }

    public function rejectsUnknownPath(): void
    {
        Assert::null($this->resolve(['key' => 'abc'], 'other'));
        Assert::null($this->resolve(['payload' => ['id' => 'o-1']], 'payload.id.deeper'));
    }

    public function rejectsEmptyPath(): void
    {
        Assert::null($this->resolve(['key' => 'abc'], ''));
    }

    public function doesNotReachNonPublicProperties(): void
    {
        Assert::null($this->resolve(['payload' => new Payload('o-1')], 'payload.secret'));
    }

    public function passesBlankValueThroughForTheKeyResolverToReject(): void
    {
        // Not null: the path is correct and the value is simply blank, which is a runtime fact rather
        // than a misconfigured attribute. DefaultKeyResolver turns it into MissingKeyException.
        Assert::same($this->resolve(['key' => '  '], 'key'), '  ');
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private function resolve(array $arguments, string $path): ?string
    {
        return (new DefaultArgumentKeyResolver())->resolve($arguments, $path);
    }
}
