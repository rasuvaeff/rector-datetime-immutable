<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\RectorDateTimeImmutable\Internal\FactoryCallMap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(FactoryCallMap::class)]
final class FactoryCallMapTest
{
    #[DataProvider('functionProvider')]
    public function mapsFactoryFunctions(string $functionName, ?string $expected): void
    {
        Assert::same((new FactoryCallMap())->immutableEquivalent($functionName), $expected);
    }

    public static function functionProvider(): iterable
    {
        yield 'date_create' => ['date_create', 'date_create_immutable'];
        yield 'date_create_from_format' => ['date_create_from_format', 'date_create_immutable_from_format'];
        yield 'DATE_CREATE uppercase' => ['DATE_CREATE', 'date_create_immutable'];
        yield 'already immutable' => ['date_create_immutable', null];
        yield 'unrelated function' => ['time', null];
        yield 'empty string' => ['', null];
    }

    #[DataProvider('staticFactoryProvider')]
    public function classifiesSharedStaticFactories(string $methodName, bool $expected): void
    {
        Assert::same((new FactoryCallMap())->isSharedStaticFactory($methodName), $expected);
    }

    public static function staticFactoryProvider(): iterable
    {
        yield 'createFromFormat' => ['createFromFormat', true];
        yield 'createFromInterface' => ['createFromInterface', true];
        yield 'createFromTimestamp' => ['createFromTimestamp', true];
        yield 'CREATEFROMFORMAT uppercase' => ['CREATEFROMFORMAT', true];
        yield 'CREATEFROMTIMESTAMP uppercase' => ['CREATEFROMTIMESTAMP', true];
        yield 'createFromImmutable has no immutable counterpart' => ['createFromImmutable', false];
        yield 'createFromMutable belongs to DateTimeImmutable' => ['createFromMutable', false];
        yield 'getLastErrors is a reader' => ['getLastErrors', false];
    }

    #[DataProvider('proceduralImmutableFactoryProvider')]
    public function classifiesProceduralImmutableFactories(string $functionName, bool $expected): void
    {
        Assert::same((new FactoryCallMap())->isProceduralImmutableFactory($functionName), $expected);
    }

    public static function proceduralImmutableFactoryProvider(): iterable
    {
        yield 'date_create_immutable' => ['date_create_immutable', true];
        yield 'date_create_immutable_from_format' => ['date_create_immutable_from_format', true];
        yield 'DATE_CREATE_IMMUTABLE uppercase' => ['DATE_CREATE_IMMUTABLE', true];
        yield 'date_create is mutable' => ['date_create', false];
        yield 'date_create_from_format is mutable' => ['date_create_from_format', false];
        yield 'unrelated function' => ['time', false];
        yield 'empty string' => ['', false];
    }

    #[Property(runs: 300)]
    public function lookupsAreCaseInsensitive(string $name, int $caseMask): void
    {
        $map = new FactoryCallMap();
        $mixedCase = CaseMask::apply($name, $caseMask);

        // A mask of zero leaves the name untouched, which asserts nothing
        // about case handling; the gate keeps the runs that do the work from
        // quietly becoming a minority.
        Classify::cover($mixedCase !== $name, 'case actually changed', 60.0);
        Classify::when($caseMask === 0, 'name left as written');

        Assert::same($map->immutableEquivalent($mixedCase), $map->immutableEquivalent($name));
        Assert::same($map->isSharedStaticFactory($mixedCase), $map->isSharedStaticFactory($name));
        Assert::same($map->isProceduralImmutableFactory($mixedCase), $map->isProceduralImmutableFactory($name));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function lookupsAreCaseInsensitiveGenerators(): array
    {
        return [
            'name' => Gen::oneOf(
                'date_create',
                'date_create_from_format',
                'date_create_immutable',
                'createFromFormat',
                'createFromInterface',
                'createFromTimestamp',
                'createFromImmutable',
                'now',
            ),
            'caseMask' => Gen::intBetween(0, (1 << 16) - 1),
        ];
    }

    #[Property(runs: 400, timeoutMs: 1000)]
    public function eachLookupAnswersYesOnlyForItsOwnCatalogue(string $name): void
    {
        $map = new FactoryCallMap();
        $lower = \strtolower($name);

        $mutableFactory = \in_array($lower, ['date_create', 'date_create_from_format'], strict: true);
        $immutableFactory = \in_array($lower, ['date_create_immutable', 'date_create_immutable_from_format'], strict: true);
        $sharedStatic = \in_array($lower, ['createfromformat', 'createfrominterface', 'createfromtimestamp'], strict: true);

        Classify::cover($mutableFactory, 'a mutable procedural factory', 10.0);
        Classify::cover($immutableFactory, 'an immutable procedural factory', 10.0);
        Classify::cover($sharedStatic, 'a shared static factory', 10.0);
        Classify::cover(
            !$mutableFactory && !$immutableFactory && !$sharedStatic,
            'outside every catalogue',
            25.0,
        );

        // Three catalogues that must not bleed into one another. A rewriter
        // that answered yes for a name it does not know would rewrite user code
        // into a call that does not exist, and one that answered yes across
        // catalogues would treat an already-immutable factory as needing the
        // rewrite it is the target of.
        Assert::same($map->immutableEquivalent($name) !== null, $mutableFactory);
        Assert::same($map->isProceduralImmutableFactory($name), $immutableFactory);
        Assert::same($map->isSharedStaticFactory($name), $sharedStatic);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function eachLookupAnswersYesOnlyForItsOwnCatalogueGenerators(): array
    {
        return [
            'name' => Gen::frequency([
                [1, Gen::elements(['date_create', 'date_create_from_format'])],
                [1, Gen::elements(['date_create_immutable', 'date_create_immutable_from_format'])],
                [1, Gen::elements(['createFromFormat', 'createFromInterface', 'createFromTimestamp'])],
                // Names from the same alphabets, so a near miss such as
                // `date_created` or `createFromImmutable` is an ordinary draw
                // rather than a lucky one.
                [1, Gen::regex('date_[a-z_]{0,12}')],
                [1, Gen::regex('createFrom[A-Za-z]{0,10}')],
            ]),
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eachLookupAnswersYesOnlyForItsOwnCatalogueExamples(): iterable
    {
        yield 'empty name' => [''];
        yield 'catalogued, mixed case' => ['DaTe_CrEaTe'];
        yield 'the immutable twin is not itself a target' => ['date_create_immutable'];
        // Documented as deliberately absent: it has no counterpart on
        // DateTimeImmutable and marks code that wants mutability.
        yield 'createFromImmutable is deliberately absent' => ['createFromImmutable'];
        yield 'one character longer' => ['date_created'];
        yield 'one character shorter' => ['date_creat'];
        yield 'prefixed' => ['my_date_create'];
    }
}
