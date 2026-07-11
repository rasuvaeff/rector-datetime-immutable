<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Internal;

use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

/**
 * Pure AST rewrite of a type declaration node: `DateTime` becomes
 * `\DateTimeImmutable`, including inside nullable and union types. Name
 * resolution stays with the caller via the two predicates, keeping this class
 * free of reflection.
 *
 * Guards against emitting invalid PHP: a union that already contains
 * `DateTimeImmutable` is left untouched (the rewrite would duplicate the type,
 * a compile error), and intersection types are never rewritten (`DateTime`
 * inside an intersection is exotic enough that any rewrite is a guess).
 *
 * @internal
 */
final readonly class DateTimeTypeRewriter
{
    /**
     * Returns the replacement type node, or null when nothing must change.
     *
     * @param callable(Name): bool $isDateTimeName
     * @param callable(Name): bool $isDateTimeImmutableName
     */
    public function rewritten(
        Identifier|Name|ComplexType $type,
        callable $isDateTimeName,
        callable $isDateTimeImmutableName,
    ): Name|NullableType|UnionType|null {
        if ($type instanceof Name) {
            return $isDateTimeName($type) ? new FullyQualified('DateTimeImmutable') : null;
        }

        if ($type instanceof NullableType) {
            $inner = $this->rewritten($type->type, $isDateTimeName, $isDateTimeImmutableName);

            if (!$inner instanceof Name) {
                return null;
            }

            $type->type = $inner;

            return $type;
        }

        if (!$type instanceof UnionType) {
            return null;
        }

        foreach ($type->types as $member) {
            if ($this->containsDateTimeImmutable($member, $isDateTimeImmutableName)) {
                return null;
            }
        }

        $changed = false;

        foreach ($type->types as $position => $member) {
            if ($member instanceof IntersectionType) {
                continue;
            }

            $inner = $this->rewritten($member, $isDateTimeName, $isDateTimeImmutableName);

            if ($inner instanceof Name) {
                $type->types[$position] = $inner;
                $changed = true;
            }
        }

        return $changed ? $type : null;
    }

    /**
     * Read-only variant of {@see rewritten()}: reports whether the rewrite
     * would change the type without touching the node. `rewritten()` mutates
     * nullable/union nodes in place, so dry-run callers must use this instead.
     *
     * @param callable(Name): bool $isDateTimeName
     * @param callable(Name): bool $isDateTimeImmutableName
     */
    public function wouldRewrite(
        Identifier|Name|ComplexType $type,
        callable $isDateTimeName,
        callable $isDateTimeImmutableName,
    ): bool {
        if ($type instanceof Name) {
            return $isDateTimeName($type);
        }

        if ($type instanceof NullableType) {
            return $type->type instanceof Name && $isDateTimeName($type->type);
        }

        if (!$type instanceof UnionType) {
            return false;
        }

        foreach ($type->types as $member) {
            if ($this->containsDateTimeImmutable($member, $isDateTimeImmutableName)) {
                return false;
            }
        }

        foreach ($type->types as $member) {
            if ($member instanceof Name && $isDateTimeName($member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(Name): bool $isDateTimeImmutableName
     */
    private function containsDateTimeImmutable(
        Identifier|Name|IntersectionType $type,
        callable $isDateTimeImmutableName,
    ): bool {
        if ($type instanceof Name) {
            return $isDateTimeImmutableName($type);
        }

        if ($type instanceof IntersectionType) {
            foreach ($type->types as $member) {
                if ($member instanceof Name && $isDateTimeImmutableName($member)) {
                    return true;
                }
            }
        }

        return false;
    }
}
