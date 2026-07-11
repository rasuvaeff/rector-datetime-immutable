<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
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
}
