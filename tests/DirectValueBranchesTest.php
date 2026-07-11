<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DirectValueBranches;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DirectValueBranches::class)]
final class DirectValueBranchesTest
{
    public function plainExpressionIsItsOnlyBranch(): void
    {
        $expr = new Variable('a');

        Assert::same((new DirectValueBranches())->branches($expr), [$expr]);
    }

    public function ternaryYieldsBothValueBranches(): void
    {
        $if = new Variable('a');
        $else = new Variable('b');

        Assert::same(
            (new DirectValueBranches())->branches(new Ternary(new Variable('cond'), $if, $else)),
            [$if, $else],
        );
    }

    public function shortTernaryYieldsConditionAndElse(): void
    {
        $cond = new Variable('a');
        $else = new Variable('b');

        Assert::same(
            (new DirectValueBranches())->branches(new Ternary($cond, null, $else)),
            [$cond, $else],
        );
    }

    public function coalesceYieldsBothSides(): void
    {
        $left = new Variable('a');
        $right = new Variable('b');

        Assert::same(
            (new DirectValueBranches())->branches(new Coalesce($left, $right)),
            [$left, $right],
        );
    }

    public function coalesceFlattensNestedBranchesOnBothSides(): void
    {
        $a = new Variable('a');
        $b = new Variable('b');
        $c = new Variable('c');
        $d = new Variable('d');
        $expr = new Coalesce(
            new Ternary(new Variable('cond'), $a, $b),
            new Coalesce($c, $d),
        );

        Assert::same((new DirectValueBranches())->branches($expr), [$a, $b, $c, $d]);
    }

    public function nestedOperatorsFlattenInEvaluationOrder(): void
    {
        $a = new Variable('a');
        $b = new Variable('b');
        $c = new Variable('c');
        $d = new Variable('d');
        $expr = new Ternary(
            new Variable('cond'),
            new Coalesce($a, $b),
            new Ternary($c, null, $d),
        );

        Assert::same((new DirectValueBranches())->branches($expr), [$a, $b, $c, $d]);
    }
}
