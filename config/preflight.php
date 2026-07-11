<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\MutableDateTimeBoundaryRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([
        MutableDateTimeBoundaryRector::class,
    ]);
