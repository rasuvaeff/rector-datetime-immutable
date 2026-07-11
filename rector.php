<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSkip([
        // `@var mixed` on php-parser attribute reads is load-bearing: it
        // suppresses Psalm's MixedAssignment at the untyped getAttribute()
        // boundary (UseImportUsageScanner).
        RemoveUselessVarTagRector::class,
    ]);
