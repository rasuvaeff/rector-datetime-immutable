<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withConfiguredRule(DateTimeImmutableRector::class, [
        DateTimeImmutableRector::CONSTRUCTORS => false,
        DateTimeImmutableRector::TYPEHINTS => false,
        DateTimeImmutableRector::PROPERTIES => true,
    ]);
