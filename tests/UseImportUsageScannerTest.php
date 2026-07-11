<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Rasuvaeff\RectorDateTimeImmutable\Internal\UseImportUsageScanner;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(UseImportUsageScanner::class)]
final class UseImportUsageScannerTest
{
    #[DataProvider('usageProvider')]
    public function detectsUsage(string $code, string $alias, bool $expected): void
    {
        $stmts = (new ParserFactory())->createForHostVersion()->parse($code);

        Assert::same((new UseImportUsageScanner())->isUsed($stmts ?? [], $alias), $expected);
    }

    /**
     * Rector's traversal replaces resolvable names with fully-qualified
     * nodes, keeping the authored spelling in the originalName attribute —
     * usage detection must look through that replacement.
     */
    #[DataProvider('resolvedUsageProvider')]
    public function detectsUsageThroughResolvedNames(string $code, string $alias, bool $expected): void
    {
        $stmts = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser(
            new NameResolver(options: ['preserveOriginalNames' => true, 'replaceNodes' => true]),
        );
        $resolved = $traverser->traverse($stmts);

        Assert::same((new UseImportUsageScanner())->isUsed($resolved, $alias), $expected);
    }

    public static function resolvedUsageProvider(): iterable
    {
        yield 'resolved alias reference still counts' => [
            '<?php use DateTime as DT; $d = new DT();',
            'DT',
            true,
        ];

        yield 'resolved plain reference still counts' => [
            '<?php use DateTime; $d = new DateTime();',
            'DateTime',
            true,
        ];

        yield 'authored fully qualified reference still does not count' => [
            '<?php use DateTime; $d = new \DateTime();',
            'DateTime',
            false,
        ];
    }

    public static function usageProvider(): iterable
    {
        yield 'plain reference' => [
            '<?php use DateTime; $d = new DateTime();',
            'DateTime',
            true,
        ];

        yield 'fully qualified references do not count' => [
            '<?php use DateTime; $d = new \DateTime(); $e = new \DateTimeImmutable();',
            'DateTime',
            false,
        ];

        yield 'ast lookup is case-insensitive' => [
            '<?php use DateTime; $d = new DATETIME();',
            'DateTime',
            true,
        ];

        yield 'alias reference counts for the alias' => [
            '<?php use DateTime as DT; $d = DT::createFromFormat("Y", "2026");',
            'DT',
            true,
        ];

        yield 'unreferenced alias' => [
            '<?php use DateTime as DT; $d = new \DateTimeImmutable();',
            'DT',
            false,
        ];

        yield 'name inside the use statement itself does not count' => [
            '<?php use DateTime;',
            'DateTime',
            false,
        ];

        yield 'names inside group uses do not count' => [
            '<?php use Foo\{Bar, Baz};',
            'Bar',
            false,
        ];

        yield 'qualified reference matches by first segment' => [
            '<?php use DateTime as DT; $x = DT\Whatever::class;',
            'DT',
            true,
        ];

        yield 'relative name does not count' => [
            '<?php namespace App; use DateTime; $d = new namespace\DateTime();',
            'DateTime',
            false,
        ];

        yield 'plain usage after a fully qualified name still counts' => [
            '<?php use DateTime; $a = new \Other(); $b = new DateTime();',
            'DateTime',
            true,
        ];

        yield 'instanceof reference' => [
            '<?php use DateTime; var_dump($x instanceof DateTime);',
            'DateTime',
            true,
        ];

        yield 'attribute reference' => [
            '<?php use DateTime; #[DateTime] class A {}',
            'DateTime',
            true,
        ];

        yield 'parameter type reference' => [
            '<?php use DateTime; function f(DateTime $x): void {}',
            'DateTime',
            true,
        ];

        yield 'docblock type reference keeps the import' => [
            '<?php use DateTime; class A { /** @var DateTime */ private $x; }',
            'DateTime',
            true,
        ];

        yield 'fully qualified docblock type does not count' => [
            '<?php use DateTime; class A { /** @var \DateTime */ private $x; }',
            'DateTime',
            false,
        ];

        yield 'docblock immutable type does not count' => [
            '<?php use DateTime; class A { /** @var DateTimeImmutable */ private $x; }',
            'DateTime',
            false,
        ];

        yield 'mutable-datetime marker does not count' => [
            '<?php use DateTime; class A { /** @mutable-datetime */ private $x; }',
            'DateTime',
            false,
        ];

        yield 'comment prose mention keeps the import' => [
            "<?php use DateTime; // legacy DateTime flows here\n\$x = 1;",
            'DateTime',
            true,
        ];

        yield 'hyphen-joined comment token does not count' => [
            "<?php use DateTime; // legacy-DateTime binding\n\$x = 1;",
            'DateTime',
            false,
        ];

        yield 'dollar-prefixed comment token does not count' => [
            "<?php use DateTime; // \$DateTime holds state\n\$x = 1;",
            'DateTime',
            false,
        ];

        yield 'alias is matched literally in comments' => [
            "<?php // DxT value\n\$x = 1;",
            'D.T',
            false,
        ];
    }
}
