<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use Testo\Assert;

/**
 * Shared e2e harness: copies a fixture suite into a temp dir, runs the real
 * `rector process` binary over it — the same path a consumer takes — and
 * compares every produced file with its committed `.expected` counterpart.
 * `.php.fixture` naming keeps the fixtures out of cs-fixer/psalm/rector of
 * this package itself.
 */
final readonly class FixtureSuite
{
    /**
     * $passes > 1 models the documented "run until clean" migration flow:
     * within one rector run type inference still sees the pre-migration
     * types, so lost mutations created by the construction migration are
     * only visible to the next run.
     */
    public static function assertTransformed(
        string $suite,
        string $config,
        int $passes = 1,
        bool $execute = false,
    ): void {
        $fixtureDir = __DIR__ . '/fixture/' . $suite;
        $workDir = sys_get_temp_dir() . '/rector-datetime-immutable-' . $suite . '-' . bin2hex(random_bytes(4));

        mkdir($workDir, 0o777, true);

        try {
            $fixtures = glob($fixtureDir . '/*.php.fixture') ?: [];
            Assert::true($fixtures !== [], 'fixture suite must not be empty');

            foreach ($fixtures as $fixture) {
                copy($fixture, $workDir . '/' . basename($fixture, '.fixture'));
            }

            $output = '';

            for ($pass = 0; $pass < $passes; ++$pass) {
                [$exitCode, $output] = self::runRector($workDir, $config);
                Assert::same($exitCode, 0, 'rector process failed: ' . $output);
            }

            foreach ($fixtures as $fixture) {
                $fileName = basename($fixture, '.fixture');
                $expected = $fixtureDir . '/' . $fileName . '.expected';
                $actual = $workDir . '/' . $fileName;

                Assert::true(is_file($expected), $fileName . ' is missing its .expected counterpart');
                Assert::same(
                    (string) file_get_contents($actual),
                    (string) file_get_contents($expected),
                    $fileName . "\n" . $output,
                );
                self::assertValidPhp($actual);

                if ($execute) {
                    self::assertExecutable($actual);
                }
            }
        } finally {
            foreach (glob($workDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($workDir);
        }
    }

    private static function assertValidPhp(string $file): void
    {
        $command = sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file));

        exec($command, $lines, $exitCode);

        Assert::same(
            $exitCode,
            0,
            'generated PHP is invalid: ' . implode("\n", $lines),
        );
    }

    private static function assertExecutable(string $file): void
    {
        $command = sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file));

        exec($command, $lines, $exitCode);

        Assert::same(
            $exitCode,
            0,
            'generated PHP failed at runtime: ' . implode("\n", $lines),
        );
    }

    /**
     * @return array{int, string}
     */
    public static function runRector(string $paths, string $config): array
    {
        $command = sprintf(
            '%s process %s --config %s --no-progress-bar --no-diffs --clear-cache 2>&1',
            escapeshellarg(dirname(__DIR__) . '/vendor/bin/rector'),
            escapeshellarg($paths),
            escapeshellarg($config),
        );

        exec($command, $lines, $exitCode);

        return [$exitCode, implode("\n", $lines)];
    }
}
