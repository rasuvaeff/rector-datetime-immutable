<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\LostDateTimeMutationRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withConfiguredRule(LostDateTimeMutationRector::class, [
        LostDateTimeMutationRector::MODE => LostDateTimeMutationRector::MODE_REPORT,
    ]);
