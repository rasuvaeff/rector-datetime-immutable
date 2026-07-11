<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Internal;

use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;

/**
 * Detects ORM-mapped members: a `Column` attribute (`#[Column]`,
 * `#[ORM\Column]`, `#[Doctrine\ORM\Mapping\Column]`) or a `@Column` /
 * `@ORM\Column` docblock annotation. The ORM hydrates such members itself and
 * the expected class depends on the mapped type (`datetime` vs
 * `datetime_immutable`), so type migration must leave them alone — unless the
 * doctrine-columns option co-migrates the mapping: DBAL ships a native
 * immutable counterpart for every mutable date type, so an attribute-mapped
 * column whose `type` is one of those (or omitted — Doctrine then infers the
 * type from the PHP declaration) can migrate together with its PHP type.
 * Docblock annotations, positional attribute arguments and custom type
 * expressions never co-migrate.
 *
 * @internal
 */
final readonly class DoctrineColumnDetector
{
    private const string ANNOTATION_PATTERN = '/@(?:ORM\\\\)?Column\b/';

    private const array COLUMN_TYPE_MAP = [
        'datetime' => 'datetime_immutable',
        'date' => 'date_immutable',
        'time' => 'time_immutable',
        'datetimetz' => 'datetimetz_immutable',
    ];

    private const array COLUMN_CONST_MAP = [
        'DATETIME_MUTABLE' => 'DATETIME_IMMUTABLE',
        'DATE_MUTABLE' => 'DATE_IMMUTABLE',
        'TIME_MUTABLE' => 'TIME_IMMUTABLE',
        'DATETIMETZ_MUTABLE' => 'DATETIMETZ_IMMUTABLE',
    ];

    public function isMappedColumn(Property|Param $node): bool
    {
        if ($this->columnAttribute($node) instanceof Attribute) {
            return true;
        }

        $docComment = $node->getDocComment();

        return $docComment instanceof \PhpParser\Comment\Doc
            && preg_match(self::ANNOTATION_PATTERN, $docComment->getText()) === 1;
    }

    public function isCoMigratableColumn(Property|Param $node): bool
    {
        $attribute = $this->columnAttribute($node);

        if (!$attribute instanceof Attribute) {
            return false;
        }

        $typeValue = null;

        foreach ($attribute->args as $argument) {
            if (!$argument->name instanceof Identifier) {
                return false;
            }

            if ($argument->name->toString() === 'type') {
                $typeValue = $argument->value;
            }
        }

        if ($typeValue === null) {
            return true;
        }

        if ($typeValue instanceof String_) {
            return isset(self::COLUMN_TYPE_MAP[$typeValue->value]);
        }

        return $typeValue instanceof ClassConstFetch
            && $typeValue->class instanceof Name
            && strcasecmp($typeValue->class->getLast(), 'Types') === 0
            && $typeValue->name instanceof Identifier
            && isset(self::COLUMN_CONST_MAP[$typeValue->name->toString()]);
    }

    /**
     * Rewrites the `type` argument of a co-migratable Column attribute to the
     * immutable DBAL variant; an absent argument means Doctrine infers the
     * mapping from the migrated PHP type and nothing needs rewriting.
     */
    public function migrateColumnType(Property|Param $node): void
    {
        $attribute = $this->columnAttribute($node);

        foreach ($attribute instanceof Attribute ? $attribute->args : [] as $argument) {
            if (!$argument->name instanceof Identifier || $argument->name->toString() !== 'type') {
                continue;
            }

            if ($argument->value instanceof String_ && isset(self::COLUMN_TYPE_MAP[$argument->value->value])) {
                $argument->value = new String_(self::COLUMN_TYPE_MAP[$argument->value->value]);
            } elseif (
                $argument->value instanceof ClassConstFetch
                && $argument->value->name instanceof Identifier
                && isset(self::COLUMN_CONST_MAP[$argument->value->name->toString()])
            ) {
                $argument->value->name = new Identifier(
                    self::COLUMN_CONST_MAP[$argument->value->name->toString()],
                );
            }
        }
    }

    private function columnAttribute(Property|Param $node): ?Attribute
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attribute) {
                if (strtolower($attribute->name->getLast()) === 'column') {
                    return $attribute;
                }
            }
        }

        return null;
    }
}
