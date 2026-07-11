<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Internal;

/**
 * Whitelist of `DateTimeImmutable` methods that mutate a `DateTime` in place
 * but return a new instance on the immutable class — the methods whose ignored
 * return value is a lost mutation.
 *
 * PHP method names are case-insensitive, so the lookup is too.
 *
 * @internal
 */
final readonly class DateTimeMutatorCatalog
{
    private const array MUTATORS = [
        'modify',
        'add',
        'sub',
        'setdate',
        'settime',
        'setisodate',
        'settimezone',
        'settimestamp',
        'setmicrosecond',
    ];

    public function isMutator(string $methodName): bool
    {
        return \in_array(strtolower($methodName), self::MUTATORS, true);
    }
}
