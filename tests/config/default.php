<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([DateTimeImmutableRector::class]);
