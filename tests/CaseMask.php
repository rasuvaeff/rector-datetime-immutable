<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

/**
 * Deterministic per-character case flipping for the case-insensitivity
 * properties: bit N of the mask decides the case of character N.
 */
final readonly class CaseMask
{
    public static function apply(string $value, int $mask): string
    {
        $result = '';

        foreach (str_split($value) as $position => $char) {
            $result .= ($mask >> $position % 16 & 1) !== 0 ? strtoupper($char) : strtolower($char);
        }

        return $result;
    }
}
