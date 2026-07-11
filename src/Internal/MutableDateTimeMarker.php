<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Internal;

use PhpParser\Node;

/**
 * Opt-out marker: a declaration whose docblock carries `@mutable-datetime`
 * keeps its mutable `DateTime` type on purpose and is skipped by the
 * migration (analogous to how `@no-named-arguments` opts out of named-argument
 * rewrites).
 *
 * @internal
 */
final readonly class MutableDateTimeMarker
{
    public const string TAG = '@mutable-datetime';

    /**
     * Word-boundary match: `@mutable-datetime-boundary` (the acknowledge
     * marker for reviewed boundary calls) must not read as this opt-out tag.
     */
    private const string TAG_PATTERN = '/' . self::TAG . '(?![\w-])/';

    public function isMarked(Node $node): bool
    {
        $docComment = $node->getDocComment();

        return $docComment instanceof \PhpParser\Comment\Doc
            && preg_match(self::TAG_PATTERN, $docComment->getText()) === 1;
    }
}
