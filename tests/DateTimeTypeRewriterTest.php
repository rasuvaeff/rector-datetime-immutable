<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DateTimeTypeRewriter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DateTimeTypeRewriter::class)]
final class DateTimeTypeRewriterTest
{
    public function rewritesPlainName(): void
    {
        $rewritten = $this->rewritten(new Name('DateTime'));

        Assert::instanceOf($rewritten, FullyQualified::class);
        Assert::same($rewritten->toString(), 'DateTimeImmutable');
    }

    public function rewritesFullyQualifiedName(): void
    {
        $rewritten = $this->rewritten(new FullyQualified('DateTime'));

        Assert::instanceOf($rewritten, FullyQualified::class);
        Assert::same($rewritten->toString(), 'DateTimeImmutable');
    }

    public function leavesOtherNamesUntouched(): void
    {
        Assert::null($this->rewritten(new Name('DateTimeInterface')));
        Assert::null($this->rewritten(new FullyQualified('DateTimeImmutable')));
        Assert::null($this->rewritten(new Name('App\DateTime')));
    }

    public function leavesIdentifiersUntouched(): void
    {
        Assert::null($this->rewritten(new Identifier('string')));
    }

    public function rewritesNullableInner(): void
    {
        $type = new NullableType(new FullyQualified('DateTime'));

        $rewritten = $this->rewritten($type);

        Assert::same($rewritten, $type);
        Assert::instanceOf($type->type, FullyQualified::class);
        Assert::same($type->type->toString(), 'DateTimeImmutable');
    }

    public function leavesNullableWithoutDateTimeUntouched(): void
    {
        Assert::null($this->rewritten(new NullableType(new Identifier('int'))));
        Assert::null($this->rewritten(new NullableType(new FullyQualified('DateTimeImmutable'))));
    }

    public function rewritesUnionMemberAtItsPosition(): void
    {
        $type = new UnionType([new Identifier('false'), new FullyQualified('DateTime')]);

        $rewritten = $this->rewritten($type);

        Assert::same($rewritten, $type);
        Assert::instanceOf($type->types[0], Identifier::class);
        Assert::instanceOf($type->types[1], FullyQualified::class);
        Assert::same($type->types[1]->toString(), 'DateTimeImmutable');
    }

    public function leavesUnionAlreadyContainingImmutableUntouched(): void
    {
        $type = new UnionType([new FullyQualified('DateTime'), new FullyQualified('DateTimeImmutable')]);

        Assert::null($this->rewritten($type));
        Assert::same($type->types[0]->toString(), 'DateTime');
    }

    public function leavesUnionContainingImmutableIntersectionUntouched(): void
    {
        $intersection = new IntersectionType([new FullyQualified('DateTimeImmutable'), new Name('Marker')]);
        $type = new UnionType([$intersection, new FullyQualified('DateTime')]);

        Assert::null($this->rewritten($type));
        Assert::same($type->types[1]->toString(), 'DateTime');
    }

    public function leavesUnionWithoutDateTimeUntouched(): void
    {
        Assert::null($this->rewritten(new UnionType([new Name('Foo'), new Identifier('null')])));
    }

    public function skipsIntersectionMemberInsideUnion(): void
    {
        $intersection = new IntersectionType([new FullyQualified('DateTime'), new Name('Traversable')]);
        $type = new UnionType([$intersection, new FullyQualified('DateTime')]);

        $rewritten = $this->rewritten($type);

        Assert::same($rewritten, $type);
        Assert::same($type->types[0], $intersection);
        Assert::same($intersection->types[0]->toString(), 'DateTime');
        Assert::same($type->types[1]->toString(), 'DateTimeImmutable');
    }

    public function leavesIntersectionTypeUntouched(): void
    {
        $type = new IntersectionType([new FullyQualified('DateTime'), new Name('Traversable')]);

        Assert::null($this->rewritten($type));
    }

    public function wouldRewriteMatchesPlainNames(): void
    {
        Assert::true($this->wouldRewrite(new Name('DateTime')));
        Assert::true($this->wouldRewrite(new FullyQualified('DateTime')));
        Assert::false($this->wouldRewrite(new Name('DateTimeInterface')));
        Assert::false($this->wouldRewrite(new FullyQualified('DateTimeImmutable')));
        Assert::false($this->wouldRewrite(new Identifier('string')));
    }

    public function wouldRewriteMatchesNullableWithoutMutatingIt(): void
    {
        $inner = new FullyQualified('DateTime');
        $type = new NullableType($inner);

        Assert::true($this->wouldRewrite($type));
        Assert::same($type->type, $inner);
        Assert::same($inner->toString(), 'DateTime');
        Assert::false($this->wouldRewrite(new NullableType(new Identifier('int'))));
        Assert::false($this->wouldRewrite(new NullableType(new FullyQualified('DateTimeImmutable'))));
    }

    public function wouldRewriteMatchesUnionWithoutMutatingIt(): void
    {
        $member = new FullyQualified('DateTime');
        $type = new UnionType([new Identifier('false'), $member]);

        Assert::true($this->wouldRewrite($type));
        Assert::same($type->types[1], $member);
        Assert::same($member->toString(), 'DateTime');
    }

    public function wouldRewriteRejectsUnionAlreadyContainingImmutable(): void
    {
        Assert::false($this->wouldRewrite(
            new UnionType([new FullyQualified('DateTime'), new FullyQualified('DateTimeImmutable')]),
        ));
    }

    public function wouldRewriteRejectsUnionContainingImmutableIntersection(): void
    {
        $intersection = new IntersectionType([new FullyQualified('DateTimeImmutable'), new Name('Marker')]);

        Assert::false($this->wouldRewrite(new UnionType([$intersection, new FullyQualified('DateTime')])));
    }

    public function wouldRewriteRejectsUnionWithoutDateTime(): void
    {
        Assert::false($this->wouldRewrite(new UnionType([new Name('Foo'), new Identifier('null')])));
    }

    public function wouldRewriteIgnoresIntersectionMemberButSeesPlainSibling(): void
    {
        $intersection = new IntersectionType([new FullyQualified('DateTime'), new Name('Traversable')]);

        Assert::true($this->wouldRewrite(new UnionType([$intersection, new FullyQualified('DateTime')])));
        Assert::false($this->wouldRewrite(new UnionType([$intersection, new Name('Foo')])));
    }

    public function wouldRewriteRejectsIntersectionType(): void
    {
        Assert::false($this->wouldRewrite(new IntersectionType([new FullyQualified('DateTime'), new Name('Traversable')])));
    }

    #[Property(runs: 300)]
    public function wouldRewriteAgreesWithRewritten(array $spec): void
    {
        $probe = $this->typeFromSpec($spec);
        $mutated = $this->typeFromSpec($spec);

        Assert::same($this->wouldRewrite($probe), $this->rewritten($mutated) !== null);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function wouldRewriteAgreesWithRewrittenGenerators(): array
    {
        return self::rewriteIsIdempotentGenerators();
    }

    #[Property(runs: 300)]
    public function rewriteIsIdempotent(array $spec): void
    {
        $type = $this->typeFromSpec($spec);

        $first = $this->rewritten($type);
        $second = $first === null ? null : $this->rewritten($first);

        Assert::null($second);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function rewriteIsIdempotentGenerators(): array
    {
        $leaf = Gen::tuple(
            Gen::oneOf('DateTime', 'DateTimeImmutable', 'DateTimeInterface', 'string'),
            Gen::bool(),
        );

        return [
            'spec' => Gen::map(
                Gen::tuple(Gen::oneOf('name', 'nullable', 'union'), Gen::nonEmptyArrayOf($leaf, 4)),
                static fn(array $pair): array => ['kind' => $pair[0], 'leaves' => array_values($pair[1])],
            ),
        ];
    }

    /**
     * @param array{kind: string, leaves: non-empty-list<array{string, bool}>} $spec
     */
    private function typeFromSpec(array $spec): Identifier|Name|ComplexType
    {
        $nodes = array_map(
            static fn(array $leaf): Identifier|Name => $leaf[0] === 'string'
                ? new Identifier('string')
                : ($leaf[1] ? new FullyQualified($leaf[0]) : new Name($leaf[0])),
            $spec['leaves'],
        );

        return match ($spec['kind']) {
            'name' => $nodes[0],
            'nullable' => $nodes[0] instanceof Identifier && $nodes[0]->name === 'string'
                ? new NullableType(new Identifier('int'))
                : new NullableType($nodes[0]),
            default => new UnionType(\count($nodes) > 1 ? $nodes : [...$nodes, new Identifier('null')]),
        };
    }

    private function rewritten(Identifier|Name|ComplexType $type): Name|NullableType|UnionType|null
    {
        return (new DateTimeTypeRewriter())->rewritten(
            $type,
            static fn(Name $name): bool => strcasecmp($name->toString(), 'DateTime') === 0,
            static fn(Name $name): bool => strcasecmp($name->toString(), 'DateTimeImmutable') === 0,
        );
    }

    private function wouldRewrite(Identifier|Name|ComplexType $type): bool
    {
        return (new DateTimeTypeRewriter())->wouldRewrite(
            $type,
            static fn(Name $name): bool => strcasecmp($name->toString(), 'DateTime') === 0,
            static fn(Name $name): bool => strcasecmp($name->toString(), 'DateTimeImmutable') === 0,
        );
    }
}
