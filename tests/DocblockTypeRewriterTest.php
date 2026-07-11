<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use Rasuvaeff\RectorDateTimeImmutable\Internal\DocblockTypeRewriter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DocblockTypeRewriter::class)]
final class DocblockTypeRewriterTest
{
    #[DataProvider('varTagProvider')]
    public function rewritesVarTags(string $docText, ?string $expected): void
    {
        Assert::same((new DocblockTypeRewriter())->rewrittenVarTag($docText), $expected);
    }

    #[DataProvider('paramTagProvider')]
    public function rewritesParamTags(string $docText, string $parameterName, ?string $expected): void
    {
        Assert::same((new DocblockTypeRewriter())->rewrittenParamTag($docText, $parameterName), $expected);
    }

    #[DataProvider('returnTagProvider')]
    public function rewritesReturnTags(string $docText, ?string $expected): void
    {
        Assert::same((new DocblockTypeRewriter())->rewrittenReturnTag($docText), $expected);
    }

    public static function varTagProvider(): iterable
    {
        yield 'plain type' => [
            '/** @var DateTime */',
            '/** @var \DateTimeImmutable */',
        ];

        yield 'fully qualified nullable union' => [
            '/** @var \DateTime|null */',
            '/** @var \DateTimeImmutable|null */',
        ];

        yield 'question-mark nullable' => [
            '/** @var ?DateTime */',
            '/** @var ?\DateTimeImmutable */',
        ];

        yield 'array shorthand' => [
            '/** @var DateTime[] */',
            '/** @var \DateTimeImmutable[] */',
        ];

        yield 'generic with inner space stays one token' => [
            '/** @var array<int, DateTime> */',
            '/** @var array<int, \DateTimeImmutable> */',
        ];

        yield 'description is preserved' => [
            '/** @var DateTime|null the last run marker */',
            '/** @var \DateTimeImmutable|null the last run marker */',
        ];

        yield 'union already immutable stays' => [
            '/** @var DateTime|DateTimeImmutable */',
            null,
        ];

        yield 'qualified other class stays' => [
            '/** @var \Legacy\DateTime */',
            null,
        ];

        yield 'unrelated type stays' => [
            '/** @var string */',
            null,
        ];

        yield 'no var tag' => [
            '/** @mutable-datetime */',
            null,
        ];

        yield 'multi-line docblock touches only the tag type' => [
            "/**\n * @var DateTime\n * @see DateTime::modify() for details\n */",
            "/**\n * @var \\DateTimeImmutable\n * @see DateTime::modify() for details\n */",
        ];

        yield 'empty tag line does not stop the scan' => [
            "/**\n * @var\n * @var \\DateTime\n */",
            "/**\n * @var\n * @var \\DateTimeImmutable\n */",
        ];

        yield 'psalm variant' => [
            '/** @psalm-var DateTime */',
            '/** @psalm-var \DateTimeImmutable */',
        ];

        yield 'callable parameters with inner space stay one token' => [
            '/** @var callable(DateTime, string): DateTime */',
            '/** @var callable(\DateTimeImmutable, string): DateTime */',
        ];

        yield 'array shape with inner spaces stays one token' => [
            '/** @var array{from: DateTime, to: DateTime} */',
            '/** @var array{from: \DateTimeImmutable, to: \DateTimeImmutable} */',
        ];

        yield 'unbalanced closer ends the token' => [
            '/** @var DateTime}DateTimeImmutable */',
            '/** @var \DateTimeImmutable}DateTimeImmutable */',
        ];
    }

    /**
     * The type-token scanner must never read past the end of the line — a
     * boundary overrun raises an uninitialized string offset warning, which
     * this test promotes to a failure.
     */
    public function scansWithoutReadingBeyondTheLine(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $rewriter = new DocblockTypeRewriter();

            Assert::same(
                $rewriter->rewrittenVarTag("/**\n * @var DateTime\n */"),
                "/**\n * @var \\DateTimeImmutable\n */",
            );
            Assert::null($rewriter->rewrittenVarTag('/** @var '));
            Assert::null($rewriter->rewrittenVarTag("/**\n * @var\n */"));
        } finally {
            restore_error_handler();
        }
    }

    public static function paramTagProvider(): iterable
    {
        yield 'matching parameter' => [
            '/** @param DateTime $when */',
            'when',
            '/** @param \DateTimeImmutable $when */',
        ];

        yield 'other parameter stays' => [
            '/** @param DateTime $other */',
            'when',
            null,
        ];

        yield 'prefix parameter name does not match' => [
            '/** @param DateTime $whenExactly */',
            'when',
            null,
        ];

        yield 'variadic generic parameter' => [
            '/** @param array<DateTime> ...$dates */',
            'dates',
            '/** @param array<\DateTimeImmutable> ...$dates */',
        ];

        yield 'variadic scalar list' => [
            '/** @param DateTime ...$dates */',
            'dates',
            '/** @param \DateTimeImmutable ...$dates */',
        ];

        yield 'by-reference parameter' => [
            '/** @param DateTime &$ref */',
            'ref',
            '/** @param \DateTimeImmutable &$ref */',
        ];

        yield 'union without immutable' => [
            '/** @param DateTime|string $value */',
            'value',
            '/** @param \DateTimeImmutable|string $value */',
        ];

        yield 'union already immutable stays' => [
            '/** @param DateTimeImmutable|DateTime $value */',
            'value',
            null,
        ];

        yield 'multi-line picks the right parameter' => [
            "/**\n * @param string \$name\n * @param \\DateTime \$from lower bound\n */",
            'from',
            "/**\n * @param string \$name\n * @param \\DateTimeImmutable \$from lower bound\n */",
        ];

        yield 'parameter name is matched literally' => [
            '/** @param DateTime $axb */',
            'a.b',
            null,
        ];
    }

    public static function returnTagProvider(): iterable
    {
        yield 'plain return' => [
            '/** @return DateTime */',
            '/** @return \DateTimeImmutable */',
        ];

        yield 'array return with description' => [
            '/** @return DateTime[] the audit trail */',
            '/** @return \DateTimeImmutable[] the audit trail */',
        ];

        yield 'already immutable stays' => [
            '/** @return \DateTimeImmutable */',
            null,
        ];

        yield 'phpstan variant' => [
            '/** @phpstan-return DateTime */',
            '/** @phpstan-return \DateTimeImmutable */',
        ];

        yield 'no return tag' => [
            '/** @param DateTime $x */',
            null,
        ];
    }
}
