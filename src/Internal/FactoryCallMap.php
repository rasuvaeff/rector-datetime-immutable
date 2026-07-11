<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Internal;

/**
 * Maps mutable `DateTime` construction entry points to their immutable
 * equivalents: procedural factory functions with a dedicated `*_immutable`
 * twin, and the static factories that exist on both classes with identical
 * semantics (so flipping the class is safe).
 *
 * `DateTime::createFromImmutable()` is deliberately absent — it has no
 * counterpart on `DateTimeImmutable` and marks code that wants mutability.
 *
 * PHP function and method names are case-insensitive, so the lookups are too.
 *
 * @internal
 */
final readonly class FactoryCallMap
{
    private const array FUNCTION_MAP = [
        'date_create' => 'date_create_immutable',
        'date_create_from_format' => 'date_create_immutable_from_format',
    ];

    private const array SHARED_STATIC_FACTORIES = [
        'createfromformat',
        'createfrominterface',
        'createfromtimestamp',
    ];

    public function immutableEquivalent(string $functionName): ?string
    {
        return self::FUNCTION_MAP[strtolower($functionName)] ?? null;
    }

    /**
     * Whether the function is one of the procedural `DateTimeImmutable`
     * factories — the targets of {@see immutableEquivalent()}. They return a
     * fresh exact `DateTimeImmutable` (or `false`), so a local assigned from
     * one is safe for lost-mutation auto-fixing.
     */
    public function isProceduralImmutableFactory(string $functionName): bool
    {
        return \in_array(strtolower($functionName), self::FUNCTION_MAP, true);
    }

    public function isSharedStaticFactory(string $methodName): bool
    {
        return \in_array(strtolower($methodName), self::SHARED_STATIC_FACTORIES, true);
    }
}
