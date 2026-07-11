<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DateTimeMutatorCatalog;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DateTimeMutatorCatalog::class)]
final class DateTimeMutatorCatalogTest
{
    #[DataProvider('methodProvider')]
    public function classifiesMethods(string $methodName, bool $expected): void
    {
        Assert::same((new DateTimeMutatorCatalog())->isMutator($methodName), $expected);
    }

    public static function methodProvider(): iterable
    {
        yield 'modify' => ['modify', true];
        yield 'add' => ['add', true];
        yield 'sub' => ['sub', true];
        yield 'setDate' => ['setDate', true];
        yield 'setTime' => ['setTime', true];
        yield 'setISODate' => ['setISODate', true];
        yield 'setTimezone' => ['setTimezone', true];
        yield 'setTimestamp' => ['setTimestamp', true];
        yield 'setMicrosecond' => ['setMicrosecond', true];
        yield 'MODIFY uppercase' => ['MODIFY', true];
        yield 'SetTimezone mixed case' => ['SetTimezone', true];
        yield 'format is a reader' => ['format', false];
        yield 'diff is a reader' => ['diff', false];
        yield 'getTimestamp is a reader' => ['getTimestamp', false];
        yield 'getTimezone is a reader' => ['getTimezone', false];
        yield 'empty string' => ['', false];
    }

    #[Property(runs: 300)]
    public function lookupIsCaseInsensitive(string $methodName, int $caseMask): void
    {
        $catalog = new DateTimeMutatorCatalog();

        Assert::same(
            $catalog->isMutator(CaseMask::apply($methodName, $caseMask)),
            $catalog->isMutator($methodName),
        );
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function lookupIsCaseInsensitiveGenerators(): array
    {
        return [
            'methodName' => Gen::oneOf('modify', 'add', 'sub', 'setTime', 'setISODate', 'format', 'diff'),
            'caseMask' => Gen::intBetween(0, (1 << 16) - 1),
        ];
    }
}
