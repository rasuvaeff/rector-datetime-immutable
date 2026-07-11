<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use Rasuvaeff\RectorDateTimeImmutable\Internal\MutableDateTimeMarker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(MutableDateTimeMarker::class)]
final class MutableDateTimeMarkerTest
{
    #[DataProvider('nodeProvider')]
    public function detectsMarker(Node $node, bool $expected): void
    {
        Assert::same((new MutableDateTimeMarker())->isMarked($node), $expected);
    }

    public static function nodeProvider(): iterable
    {
        yield 'marked property' => [self::property('/** @mutable-datetime */'), true];
        yield 'marked among other tags' => [
            self::property("/**\n * @var \\DateTime\n * @mutable-datetime\n */"),
            true,
        ];
        yield 'marked function' => [
            new Function_('legacyNow', [], ['comments' => [new Doc('/** @mutable-datetime */')]]),
            true,
        ];
        yield 'docblock without marker' => [self::property('/** @var \DateTime */'), false];
        yield 'acknowledge marker is not the opt-out tag' => [
            self::property('/** @mutable-datetime-boundary: parameter $object requires DateTime */'),
            false,
        ];
        yield 'marker with trailing description still counts' => [
            self::property('/** @mutable-datetime ORM column stays mutable */'),
            true,
        ];
        yield 'no docblock' => [self::property(null), false];
    }

    private static function property(?string $docblock): Property
    {
        $attributes = $docblock === null ? [] : ['comments' => [new Doc($docblock)]];

        return new Property(
            flags: Modifiers::PRIVATE,
            props: [new PropertyItem('raw')],
            attributes: $attributes,
        );
    }
}
