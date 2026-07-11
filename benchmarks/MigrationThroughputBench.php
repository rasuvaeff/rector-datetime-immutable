<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Benchmarks;

use Rasuvaeff\RectorDateTimeImmutable\Tests\FixtureSuite;
use Testo\Bench;

/**
 * End-to-end migration throughput: one real `rector process` run over a
 * generated file with constructions, signatures and lost mutations — the
 * actual hot path (AST traversal + both rules), unlike the catalog
 * micro-benches. The file is regenerated before every call so each run
 * migrates from the same mutable starting point; the small-file callable
 * separates rector bootstrap cost from per-node traversal cost.
 */
final class MigrationThroughputBench
{
    #[Bench(
        callables: [
            'small-file-15' => [self::class, 'migrateSmallFile'],
        ],
        calls: 1,
        iterations: 3,
    )]
    public static function migrateLargeFile150(): bool
    {
        return self::migrateGeneratedFile(150, 'large');
    }

    public static function migrateSmallFile(): bool
    {
        return self::migrateGeneratedFile(15, 'small');
    }

    private static function migrateGeneratedFile(int $functions, string $label): bool
    {
        $directory = sys_get_temp_dir() . '/rector-datetime-immutable-bench-' . $label;

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\n";

        for ($index = 0; $index < $functions; ++$index) {
            $code .= <<<PHP
                function makeDate{$label}{$index}(\\DateTime \$seed): \\DateTime
                {
                    \$date{$index} = new \\DateTime('2026-01-01');
                    \$date{$index}->modify('+{$index} days');

                    return \$date{$index};
                }

                PHP;
        }

        file_put_contents($directory . '/Generated.php', $code);

        [$exitCode] = FixtureSuite::runRector(
            $directory,
            dirname(__DIR__) . '/tests/config/migration.php',
        );

        return $exitCode === 0;
    }
}
