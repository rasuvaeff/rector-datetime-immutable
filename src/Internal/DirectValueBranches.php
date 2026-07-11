<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Internal;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Ternary;

/**
 * Enumerates the value branches an expression can evaluate to, looking through
 * ternary and null-coalescing operators. Connects a returned or assigned value
 * to the storages it may originate from.
 *
 * @internal
 */
final readonly class DirectValueBranches
{
    /**
     * @return list<Expr>
     */
    public function branches(Expr $expr): array
    {
        if ($expr instanceof Ternary) {
            return [
                ...$this->branches($expr->if ?? $expr->cond),
                ...$this->branches($expr->else),
            ];
        }

        if ($expr instanceof Coalesce) {
            return [
                ...$this->branches($expr->left),
                ...$this->branches($expr->right),
            ];
        }

        return [$expr];
    }
}
