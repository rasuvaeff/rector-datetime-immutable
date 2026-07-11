<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Internal;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Name\Relative;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;

/**
 * Decides whether a class import is still referenced anywhere in a file:
 * unqualified AST names resolved through the alias (case-insensitive, first
 * segment for qualified references) plus standalone docblock and comment
 * tokens (case-sensitive). Errs toward "used": a kept import is harmless
 * while a removed one breaks the code or its docblock resolution.
 *
 * @internal
 */
final readonly class UseImportUsageScanner
{
    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * @param array<Stmt> $fileStmts
     */
    public function isUsed(array $fileStmts, string $alias): bool
    {
        $importNames = [];
        $useStatements = $this->nodeFinder->find(
            $fileStmts,
            static fn(Node $node): bool => $node instanceof Use_ || $node instanceof GroupUse,
        );

        foreach ($useStatements as $useStatement) {
            foreach ($this->nodeFinder->findInstanceOf([$useStatement], Name::class) as $name) {
                $importNames[spl_object_id($name)] = $name;
            }
        }

        foreach ($this->nodeFinder->findInstanceOf($fileStmts, Name::class) as $name) {
            if (isset($importNames[spl_object_id($name)])) {
                continue;
            }

            // Rector's name resolution replaces resolvable names with
            // fully-qualified nodes and keeps what the author wrote in the
            // originalName attribute — only that spelling can prove the
            // import is referenced.
            /** @var mixed $originalName */
            $originalName = $name->getAttribute('originalName');
            $effective = $originalName instanceof Name ? $originalName : $name;

            if ($effective instanceof FullyQualified || $effective instanceof Relative) {
                continue;
            }

            if (strcasecmp($effective->getFirst(), $alias) === 0) {
                return true;
            }
        }

        $pattern = '/(?<![\w\\\\$-])' . preg_quote($alias, '/') . '(?!\w)/';
        $commented = $this->nodeFinder->find(
            $fileStmts,
            static fn(Node $node): bool => $node->getComments() !== [],
        );

        foreach ($commented as $node) {
            foreach ($node->getComments() as $comment) {
                if (preg_match($pattern, $comment->getText()) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
