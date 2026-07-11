<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Rasuvaeff\RectorDateTimeImmutable\LostDateTimeMutationRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withConfiguredRule(DateTimeImmutableRector::class, [
        DateTimeImmutableRector::DOCTRINE_COLUMNS => true,
    ])
    ->withRules([
        LostDateTimeMutationRector::class,
    ]);
