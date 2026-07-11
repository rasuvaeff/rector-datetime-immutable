<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DoctrineColumnDetector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DoctrineColumnDetector::class)]
final class DoctrineColumnDetectorTest
{
    #[DataProvider('propertyProvider')]
    public function detectsMappedProperties(Property $property, bool $expected): void
    {
        Assert::same((new DoctrineColumnDetector())->isMappedColumn($property), $expected);
    }

    public static function propertyProvider(): iterable
    {
        yield 'attribute Column' => [self::propertyWithAttribute(new Name('Column')), true];
        yield 'attribute ORM\Column' => [self::propertyWithAttribute(new Name('ORM\Column')), true];
        yield 'attribute FQ Doctrine\ORM\Mapping\Column' => [
            self::propertyWithAttribute(new FullyQualified('Doctrine\ORM\Mapping\Column')),
            true,
        ];
        yield 'attribute lowercase column' => [self::propertyWithAttribute(new Name('column')), true];
        yield 'attribute JoinColumn is not a column' => [
            self::propertyWithAttribute(new Name('ORM\JoinColumn')),
            false,
        ];
        yield 'unrelated attribute' => [self::propertyWithAttribute(new Name('Assert\NotNull')), false];
        yield 'docblock @ORM\Column' => [
            self::propertyWithDocblock('/** @ORM\Column(type="datetime") */'),
            true,
        ];
        yield 'docblock @Column' => [self::propertyWithDocblock('/** @Column */'), true];
        yield 'docblock @Columnist is not a column' => [
            self::propertyWithDocblock('/** @Columnist */'),
            false,
        ];
        yield 'docblock without annotations' => [self::propertyWithDocblock('/** The date. */'), false];
        yield 'no metadata at all' => [self::propertyWithAttributeGroups([]), false];
    }

    public function detectsMappedPromotedParams(): void
    {
        $detector = new DoctrineColumnDetector();

        $mapped = $this->promotedParam([new AttributeGroup([new Attribute(new Name('ORM\Column'))])]);
        $plain = $this->promotedParam([]);

        Assert::true($detector->isMappedColumn($mapped));
        Assert::false($detector->isMappedColumn($plain));
    }

    #[DataProvider('coMigratableProvider')]
    public function classifiesCoMigratableColumns(Property $property, bool $expected): void
    {
        Assert::same((new DoctrineColumnDetector())->isCoMigratableColumn($property), $expected);
    }

    public static function coMigratableProvider(): iterable
    {
        yield 'no type argument is inferred from the php type' => [
            self::columnProperty([]),
            true,
        ];
        yield 'datetime string type' => [
            self::columnProperty([new Arg(new String_('datetime'), name: new Identifier('type'))]),
            true,
        ];
        yield 'datetimetz string type' => [
            self::columnProperty([new Arg(new String_('datetimetz'), name: new Identifier('type'))]),
            true,
        ];
        yield 'custom string type stays preserved' => [
            self::columnProperty([new Arg(new String_('datetime_custom'), name: new Identifier('type'))]),
            false,
        ];
        yield 'already immutable string type stays preserved' => [
            self::columnProperty([new Arg(new String_('datetime_immutable'), name: new Identifier('type'))]),
            false,
        ];
        yield 'Types constant' => [
            self::columnProperty([new Arg(self::typesConst('DATE_MUTABLE'), name: new Identifier('type'))]),
            true,
        ];
        yield 'unknown Types constant stays preserved' => [
            self::columnProperty([new Arg(self::typesConst('DECIMAL'), name: new Identifier('type'))]),
            false,
        ];
        yield 'constant on another class stays preserved' => [
            self::columnProperty([
                new Arg(
                    new ClassConstFetch(new Name('LegacyTypes'), new Identifier('DATETIME_MUTABLE')),
                    name: new Identifier('type'),
                ),
            ]),
            false,
        ];
        yield 'constant on a dynamic class stays preserved' => [
            self::columnProperty([
                new Arg(
                    new ClassConstFetch(new Variable('typesClass'), new Identifier('DATETIME_MUTABLE')),
                    name: new Identifier('type'),
                ),
            ]),
            false,
        ];
        yield 'dynamic constant name stays preserved' => [
            self::columnProperty([
                new Arg(
                    new ClassConstFetch(new FullyQualified('Doctrine\DBAL\Types\Types'), new Variable('constant')),
                    name: new Identifier('type'),
                ),
            ]),
            false,
        ];
        yield 'positional argument stays preserved' => [
            self::columnProperty([new Arg(new String_('expires_at'))]),
            false,
        ];
        yield 'dynamic type expression stays preserved' => [
            self::columnProperty([new Arg(new Variable('type'), name: new Identifier('type'))]),
            false,
        ];
        yield 'docblock annotation never co-migrates' => [
            self::propertyWithDocblock('/** @ORM\Column(type="datetime") */'),
            false,
        ];
        yield 'not a column at all' => [
            self::propertyWithAttributeGroups([]),
            false,
        ];
    }

    public function migratesStringColumnType(): void
    {
        $argument = new Arg(new String_('datetime'), name: new Identifier('type'));
        $nameArgument = new Arg(new String_('kept'), name: new Identifier('name'));
        $property = self::columnProperty([$nameArgument, $argument]);

        (new DoctrineColumnDetector())->migrateColumnType($property);

        Assert::instanceOf($argument->value, String_::class);
        Assert::same($argument->value->value, 'datetime_immutable');
        Assert::instanceOf($nameArgument->value, String_::class);
        Assert::same($nameArgument->value->value, 'kept');
    }

    public function migratesTypesConstantColumnType(): void
    {
        $argument = new Arg(self::typesConst('DATETIMETZ_MUTABLE'), name: new Identifier('type'));
        $property = self::columnProperty([$argument]);

        (new DoctrineColumnDetector())->migrateColumnType($property);

        Assert::instanceOf($argument->value, ClassConstFetch::class);
        Assert::instanceOf($argument->value->name, Identifier::class);
        Assert::same($argument->value->name->toString(), 'DATETIMETZ_IMMUTABLE');
    }

    public function migrateLeavesNonColumnAndAbsentTypeAlone(): void
    {
        $detector = new DoctrineColumnDetector();

        $plain = self::propertyWithAttributeGroups([]);
        $detector->migrateColumnType($plain);
        Assert::same($plain->attrGroups, []);

        $nameOnly = new Arg(new String_('expires_at'), name: new Identifier('name'));
        $inferred = self::columnProperty([$nameOnly]);
        $detector->migrateColumnType($inferred);
        Assert::instanceOf($nameOnly->value, String_::class);
        Assert::same($nameOnly->value->value, 'expires_at');
    }

    public function migrateLeavesUnmappableTypeExpressionsAlone(): void
    {
        $detector = new DoctrineColumnDetector();

        $positional = new Arg(new String_('expires_at'));
        $detector->migrateColumnType(self::columnProperty([$positional]));
        Assert::instanceOf($positional->value, String_::class);
        Assert::same($positional->value->value, 'expires_at');

        $custom = new Arg(new String_('datetime_custom'), name: new Identifier('type'));
        $detector->migrateColumnType(self::columnProperty([$custom]));
        Assert::instanceOf($custom->value, String_::class);
        Assert::same($custom->value->value, 'datetime_custom');

        $unknownConstant = new Arg(self::typesConst('DECIMAL'), name: new Identifier('type'));
        $detector->migrateColumnType(self::columnProperty([$unknownConstant]));
        Assert::instanceOf($unknownConstant->value, ClassConstFetch::class);
        Assert::instanceOf($unknownConstant->value->name, Identifier::class);
        Assert::same($unknownConstant->value->name->toString(), 'DECIMAL');

        $dynamicName = new Arg(
            new ClassConstFetch(new FullyQualified('Doctrine\DBAL\Types\Types'), new Variable('constant')),
            name: new Identifier('type'),
        );
        $detector->migrateColumnType(self::columnProperty([$dynamicName]));
        Assert::instanceOf($dynamicName->value, ClassConstFetch::class);
        Assert::instanceOf($dynamicName->value->name, Variable::class);
    }

    /**
     * @param list<Arg> $args
     */
    private static function columnProperty(array $args): Property
    {
        return self::propertyWithAttributeGroups([
            new AttributeGroup([new Attribute(new Name('ORM\Column'), $args)]),
        ]);
    }

    private static function typesConst(string $constant): Expr
    {
        return new ClassConstFetch(
            new FullyQualified('Doctrine\DBAL\Types\Types'),
            new Identifier($constant),
        );
    }

    private static function propertyWithAttribute(Name $attributeName): Property
    {
        return self::propertyWithAttributeGroups([new AttributeGroup([new Attribute($attributeName)])]);
    }

    /**
     * @param list<AttributeGroup> $attrGroups
     */
    private static function propertyWithAttributeGroups(array $attrGroups, array $attributes = []): Property
    {
        return new Property(
            flags: Modifiers::PRIVATE,
            props: [new PropertyItem('placedAt')],
            attributes: $attributes,
            type: new FullyQualified('DateTime'),
            attrGroups: $attrGroups,
        );
    }

    private static function propertyWithDocblock(string $docblock): Property
    {
        return self::propertyWithAttributeGroups([], ['comments' => [new Doc($docblock)]]);
    }

    /**
     * @param list<AttributeGroup> $attrGroups
     */
    private function promotedParam(array $attrGroups): Param
    {
        return new Param(
            var: new Variable('placedAt'),
            type: new FullyQualified('DateTime'),
            flags: Modifiers::PRIVATE,
            attrGroups: $attrGroups,
        );
    }
}
