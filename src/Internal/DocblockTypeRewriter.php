<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Internal;

/**
 * Pure text rewrite of docblock tag types: standalone `DateTime` tokens in
 * the type expression of a `@var`, `@param` or `@return` tag (including the
 * `@psalm-`/`@phpstan-` variants) become `\DateTimeImmutable`. The
 * replacement is always fully qualified: an unqualified `DateTime` resolved
 * through a `use DateTime;` import that the migration removes afterwards,
 * so keeping the authored spelling would leave a dangling docblock name.
 * Only the type token after the tag is touched — descriptions, other tags
 * and prose stay intact — and a type that already references
 * `DateTimeImmutable` is left alone, mirroring the native union guard.
 * Callers invoke this only for the declaration whose native type migrated,
 * so a docblock-only declaration is never rewritten on its own.
 *
 * @internal
 */
final readonly class DocblockTypeRewriter
{
    private const string DATETIME_PATTERN = '/(?<![\w\\\\])(\\\\?)DateTime(?![\w])/';
    private const string IMMUTABLE_PATTERN = '/(?<![\w\\\\])\\\\?DateTimeImmutable(?![\w])/';

    public function rewrittenVarTag(string $docText): ?string
    {
        return $this->rewrittenTag($docText, '/@(?:psalm-|phpstan-)?var(?![\w-])/', null);
    }

    public function rewrittenReturnTag(string $docText): ?string
    {
        return $this->rewrittenTag($docText, '/@(?:psalm-|phpstan-)?return(?![\w-])/', null);
    }

    public function rewrittenParamTag(string $docText, string $parameterName): ?string
    {
        return $this->rewrittenTag($docText, '/@(?:psalm-|phpstan-)?param(?![\w-])/', $parameterName);
    }

    /**
     * Returns the full docblock with the first matching tag's type rewritten,
     * or null when nothing must change.
     *
     * @param non-empty-string $tagPattern
     */
    private function rewrittenTag(string $docText, string $tagPattern, ?string $parameterName): ?string
    {
        $segments = preg_split('/(\R)/', $docText, flags: PREG_SPLIT_DELIM_CAPTURE);

        if ($segments === false) {
            return null;
        }

        foreach ($segments as $index => $segment) {
            if (preg_match($tagPattern, $segment, $tagMatch, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $token = $this->typeToken($segment, $tagMatch[0][1] + \strlen($tagMatch[0][0]));

            if ($token === null) {
                continue;
            }

            if ($parameterName !== null && !$this->tokenTargetsParameter($segment, $token['end'], $parameterName)) {
                continue;
            }

            if (preg_match(self::IMMUTABLE_PATTERN, $token['type']) === 1) {
                return null;
            }

            $rewrittenType = preg_replace(self::DATETIME_PATTERN, '\\\\DateTimeImmutable', $token['type']);

            if (!\is_string($rewrittenType) || $rewrittenType === $token['type']) {
                return null;
            }

            $segments[$index] = substr_replace(
                $segment,
                $rewrittenType,
                $token['start'],
                $token['end'] - $token['start'],
            );

            return implode('', $segments);
        }

        return null;
    }

    /**
     * Extracts the type expression starting at the given offset: whitespace
     * splits tokens only outside `<>`, `()`, `{}` and `[]` nesting, so
     * `array<int, DateTime>` stays one token.
     *
     * @return array{type: string, start: int, end: int}|null
     */
    private function typeToken(string $line, int $offset): ?array
    {
        $length = \strlen($line);

        while ($offset < $length && ($line[$offset] === ' ' || $line[$offset] === "\t")) {
            ++$offset;
        }

        $depth = 0;
        $end = $offset;

        while ($end < $length) {
            $character = $line[$end];

            if (in_array($character, ['<', '(', '{', '['], true)) {
                ++$depth;
            } elseif (in_array($character, ['>', ')', '}', ']'], true)) {
                if ($depth === 0) {
                    break;
                }

                --$depth;
            } elseif (($character === ' ' || $character === "\t") && $depth === 0) {
                break;
            }

            ++$end;
        }

        if ($end === $offset) {
            return null;
        }

        return [
            'type' => substr($line, $offset, $end - $offset),
            'start' => $offset,
            'end' => $end,
        ];
    }

    private function tokenTargetsParameter(string $line, int $offset, string $parameterName): bool
    {
        $namePattern = '/\G\s+&?(?:\.\.\.)?\$' . preg_quote($parameterName, '/') . '(?!\w)/';

        return preg_match($namePattern, $line, offset: $offset) === 1;
    }
}
