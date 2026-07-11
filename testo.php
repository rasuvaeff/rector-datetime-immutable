<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            // fixtures declare the same function in the .fixture/.expected
            // pair — keep them out of test discovery (the e2e test reads them
            // by path); older testo plugin sets tokenize them otherwise
            location: new FinderConfig(include: ['tests'], exclude: ['tests/fixture']),
        ),
        new SuiteConfig(
            name: 'Benchmarks',
            location: ['benchmarks'],
        ),
    ],
);
