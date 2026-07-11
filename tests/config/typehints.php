<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withConfiguredRule(DateTimeImmutableRector::class, [
        DateTimeImmutableRector::CONSTRUCTORS => true,
        DateTimeImmutableRector::TYPEHINTS => true,
        DateTimeImmutableRector::PROPERTIES => true,
    ]);
