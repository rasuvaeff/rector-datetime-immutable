<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Benchmarks;

use Rasuvaeff\RectorDateTimeImmutable\Internal\DateTimeMutatorCatalog;
use Rasuvaeff\RectorDateTimeImmutable\Internal\FactoryCallMap;
use Testo\Bench;

final class DateTimeMutatorCatalogBench
{
    #[Bench(
        callables: [
            'factory-map' => [self::class, 'factoryMap'],
        ],
        calls: 1_000_000,
        iterations: 10,
    )]
    public static function mutatorLookup(): bool
    {
        $catalog = new DateTimeMutatorCatalog();

        return $catalog->isMutator('modify')
            && $catalog->isMutator('setISODate')
            && !$catalog->isMutator('format')
            && !$catalog->isMutator('diff');
    }

    public static function factoryMap(): bool
    {
        $map = new FactoryCallMap();

        return $map->immutableEquivalent('date_create') === 'date_create_immutable'
            && $map->immutableEquivalent('time') === null
            && $map->isSharedStaticFactory('createFromFormat')
            && $map->isSharedStaticFactory('createFromTimestamp')
            && !$map->isSharedStaticFactory('createFromImmutable');
    }
}
