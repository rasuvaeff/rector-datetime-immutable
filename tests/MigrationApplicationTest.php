<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use Rasuvaeff\RectorDateTimeImmutable\Cli\MigrationApplication;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MigrationApplication::class)]
final class MigrationApplicationTest
{
    public function convergesAndRunsCleanDiagnosticPass(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
            <?php

            $date = new \DateTime('2026-01-01');
            $date->modify('+1 day');
            PHP);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run([$file]);
            $source = (string) file_get_contents($file);

            Assert::same($exitCode, 0);
            Assert::string($source)->contains('$date = new \DateTimeImmutable(\'2026-01-01\');');
            Assert::string($source)->contains('$date = $date->modify(\'+1 day\');');
            Assert::string($stdout)->contains('Migration pass 3: 0 changed file(s).');
            Assert::string($stdout)->contains('Converged after 2 change-producing pass(es).');
            Assert::string($stdout)->contains('Diagnostic pass: no manual review cases found.');
            Assert::string($stdout)->contains('Summary: 1 file(s) changed across 2 change-producing pass(es); 0 manual review case(s).');
            Assert::same($stderr, '');
        } finally {
            @unlink($file);
        }
    }

    public function reportsManualCasesWithLocationWithoutChangingSource(): void
    {
        $original = <<<'PHP'
            <?php

            function moveDate(\DateTimeImmutable $date): void
            {
                $date->modify('+1 day');
            }
            PHP;
        $file = $this->temporaryPhpFile($original);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run([$file]);

            Assert::same($exitCode, 2);
            Assert::same((string) file_get_contents($file), $original);
            Assert::string($stdout)->contains('Converged after 0 change-producing pass(es).');
            Assert::string($stderr)->contains('Manual review required: 1 case(s).');
            Assert::string($stderr)->contains(basename($file) . ':5');
            Assert::string($stderr)->contains('@todo lost DateTimeImmutable mutation');
            Assert::string($stderr)->contains('hint: assign the mutator result yourself');
        } finally {
            @unlink($file);
        }
    }

    public function canDisableDiagnosticPass(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
            <?php

            function moveDate(\DateTimeImmutable $date): void
            {
                $date->modify('+1 day');
            }
            PHP);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run(['--no-report', $file]);

            Assert::same($exitCode, 0);
            Assert::string($stdout)->contains('Diagnostic pass disabled.');
            Assert::same($stderr, '');
        } finally {
            @unlink($file);
        }
    }

    public function blocksMutableBoundaryBeforeChangingSource(): void
    {
        $original = <<<'PHP'
            <?php

            class Holder
            {
                /** @mutable-datetime */
                private \DateTime $kept;

                public function setKept(\DateTime $kept): void
                {
                    $this->kept = $kept;
                }
            }

            $date = new \DateTime('2026-01-01');
            date_modify($date, '+1 day');
            PHP;
        $file = $this->temporaryPhpFile($original);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run([$file]);

            Assert::same($exitCode, 2);
            Assert::same((string) file_get_contents($file), $original);
            Assert::false(str_contains($stdout, 'Migration pass'));
            Assert::string($stderr)->contains('Migration blocked before changing files');
            Assert::string($stderr)->contains('parameter $kept feeds mutable property $kept');
            Assert::string($stderr)->contains('parameter $object requires DateTime');
            Assert::string($stderr)->contains('hint: mark the enclosing method @mutable-datetime, co-migrate ORM columns with --doctrine-columns, or migrate the storage contract first.');
            Assert::string($stderr)->contains('hint: rewrite the call for DateTimeImmutable, or review the flow and run --acknowledge-boundaries');
        } finally {
            @unlink($file);
        }
    }

    public function acknowledgesBoundaryCallsAndUnblocksPreflight(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
            <?php

            $date = new \DateTime('2026-01-01');
            date_modify($date, '+1 day');
            PHP);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run(['--acknowledge-boundaries', $file]);
            $acknowledged = (string) file_get_contents($file);

            Assert::same($exitCode, 0);
            Assert::string($stdout)->contains('Acknowledged 1 boundary call(s):');
            Assert::string($stdout)->contains('Re-run without --acknowledge-boundaries to migrate.');
            Assert::string($acknowledged)->contains('// @mutable-datetime-boundary: parameter $object requires DateTime');

            $stdout = '';
            $stderr = '';

            Assert::same($this->application($stdout, $stderr)->run(['--acknowledge-boundaries', $file]), 0);
            Assert::string($stdout)->contains('No boundary calls to acknowledge.');
            Assert::same((string) file_get_contents($file), $acknowledged);

            $stdout = '';
            $stderr = '';

            Assert::same($this->application($stdout, $stderr)->run([$file]), 0);
            Assert::string($stdout)->contains('Preflight: no mutable DateTime boundaries found.');
            Assert::string((string) file_get_contents($file))->contains("new \\DateTime('2026-01-01')");
        } finally {
            @unlink($file);
        }
    }

    public function neverAcknowledgesPreservedPropertyFeeds(): void
    {
        $original = <<<'PHP'
            <?php

            class FeedHolder
            {
                /** @mutable-datetime */
                private \DateTime $kept;

                public function setKept(\DateTime $kept): void
                {
                    $this->kept = $kept;
                }
            }
            PHP;
        $file = $this->temporaryPhpFile($original);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run(['--acknowledge-boundaries', $file]);

            Assert::same($exitCode, 2);
            Assert::same((string) file_get_contents($file), $original);
            Assert::string($stdout)->contains('No boundary calls to acknowledge.');
            Assert::string($stderr)->contains('parameter $kept feeds mutable property $kept');
        } finally {
            @unlink($file);
        }
    }

    public function dryRunPreviewsWithoutChangingFiles(): void
    {
        $original = <<<'PHP'
            <?php

            $date = new \DateTime('2026-01-01');
            $date->modify('+1 day');
            PHP;
        $file = $this->temporaryPhpFile($original);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run(['--dry-run', $file]);

            Assert::same($exitCode, 0);
            Assert::same((string) file_get_contents($file), $original);
            Assert::string($stdout)->contains('Would change ' . $file . ':');
            Assert::string($stdout)->contains('DateTimeImmutable');
            Assert::string($stdout)->contains('Summary: 1 file(s) changed across 2 change-producing pass(es); 0 manual review case(s).');
            Assert::string($stdout)->contains('Dry-run: no project files were changed.');
        } finally {
            @unlink($file);
        }
    }

    public function jsonFormatEmitsSingleMachineReadableObject(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
            <?php

            $date = new \DateTime('2026-01-01');
            $date->modify('+1 day');
            PHP);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run(['--format=json', $file]);
            $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

            Assert::same($exitCode, 0);
            Assert::false(str_contains($stdout, 'Migration pass'));
            Assert::same($payload['status'], 'clean');
            Assert::same($payload['exitCode'], 0);
            Assert::false($payload['dryRun']);
            Assert::same(\count($payload['changedFiles']), 1);
            Assert::string($payload['changedFiles'][0])->contains(basename($file));
            Assert::same($payload['manualReview'], []);
            Assert::same($payload['passes'][0], ['pass' => 1, 'changedFiles' => 1]);
        } finally {
            @unlink($file);
        }
    }

    public function jsonFormatReportsManualReviewFindings(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
            <?php

            function moveDate(\DateTimeImmutable $date): void
            {
                $date->modify('+1 day');
            }
            PHP);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run(['--format=json', $file]);
            $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

            Assert::same($exitCode, 2);
            Assert::same($payload['status'], 'manual-review');
            Assert::same($payload['exitCode'], 2);
            Assert::string($payload['manualReview'][0]['file'])->contains(basename($file));
            Assert::same($payload['manualReview'][0]['line'], 5);
            Assert::same($payload['manualReview'][0]['category'], 'lost-mutation');
        } finally {
            @unlink($file);
        }
    }

    public function githubFormatEmitsAnnotations(): void
    {
        $blocked = $this->temporaryPhpFile(<<<'PHP'
            <?php

            $date = new \DateTime('2026-01-01');
            date_modify($date, '+1 day');
            PHP);
        $manual = $this->temporaryPhpFile(<<<'PHP'
            <?php

            function moveDate(\DateTimeImmutable $date): void
            {
                $date->modify('+1 day');
            }
            PHP);
        $stdout = '';
        $stderr = '';

        try {
            Assert::same($this->application($stdout, $stderr)->run(['--format=github', $blocked]), 2);
            Assert::string($stdout)->contains('::error file=');
            Assert::string($stdout)->contains('parameter $object requires DateTime');

            $stdout = '';
            $stderr = '';

            Assert::same($this->application($stdout, $stderr)->run(['--format=github', $manual]), 2);
            Assert::string($stdout)->contains('::warning file=');
            Assert::string($stdout)->contains('line=5');
        } finally {
            @unlink($blocked);
            @unlink($manual);
        }
    }

    public function rejectsConflictingAndInvalidOptions(): void
    {
        $file = $this->temporaryPhpFile("<?php\n");
        $stdout = '';
        $stderr = '';
        $application = $this->application($stdout, $stderr);

        try {
            Assert::same($application->run(['--acknowledge-boundaries', '--dry-run', $file]), 64);
            Assert::string($stderr)->contains('cannot be combined');

            $stderr = '';

            Assert::same($application->run(['--format=xml', $file]), 64);
            Assert::string($stderr)->contains('--format must be one of: human, github, json');
        } finally {
            @unlink($file);
        }
    }

    public function doctrineColumnsFlagCoMigratesOrmEntities(): void
    {
        $original = <<<'PHP'
            <?php

            use Doctrine\ORM\Mapping as ORM;

            #[ORM\Entity]
            class Subscription
            {
                #[ORM\Column(type: 'datetime')]
                private \DateTime $expiresAt;

                public function __construct(\DateTime $expiresAt)
                {
                    $this->expiresAt = $expiresAt;
                }

                public function setExpiresAt(\DateTime $expiresAt): void
                {
                    $this->expiresAt = $expiresAt;
                }
            }
            PHP;
        $file = $this->temporaryPhpFile($original);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run([$file]);

            Assert::same($exitCode, 2);
            Assert::same((string) file_get_contents($file), $original);
            Assert::string($stderr)->contains('feeds mutable property');

            $stdout = '';
            $stderr = '';

            $exitCode = $this->application($stdout, $stderr)->run(['--doctrine-columns', $file]);
            $migrated = (string) file_get_contents($file);

            Assert::same($exitCode, 0);
            Assert::string($stdout)->contains('Preflight: no mutable DateTime boundaries found.');
            Assert::string($migrated)->contains("#[ORM\\Column(type: 'datetime_immutable')]");
            Assert::string($migrated)->contains('private \DateTimeImmutable $expiresAt;');
            Assert::string($migrated)->contains('setExpiresAt(\DateTimeImmutable $expiresAt)');
        } finally {
            @unlink($file);
        }
    }

    public function returnsFailureForInvalidRectorJson(): void
    {
        $rector = $this->temporaryPhpFile("<?php\necho 'not-json';\n");
        $source = $this->temporaryPhpFile("<?php\n");
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr, $rector)->run([$source]);

            Assert::same($exitCode, 1);
            Assert::string($stderr)->contains('Rector did not return valid JSON');
        } finally {
            @unlink($rector);
            @unlink($source);
        }
    }

    public function returnsFailureForRectorProcessError(): void
    {
        $rector = $this->temporaryPhpFile("<?php\nfwrite(STDERR, 'synthetic failure');\nexit(7);\n");
        $source = $this->temporaryPhpFile("<?php\n");
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr, $rector)->run([$source]);

            Assert::same($exitCode, 1);
            Assert::string($stderr)->contains('synthetic failure');
            Assert::string($stderr)->contains('Rector exited with code 7');
        } finally {
            @unlink($rector);
            @unlink($source);
        }
    }

    public function stopsWhenPassLimitIsReached(): void
    {
        $file = $this->temporaryPhpFile(<<<'PHP'
            <?php

            $date = new \DateTime('2026-01-01');
            $date->modify('+1 day');
            PHP);
        $stdout = '';
        $stderr = '';

        try {
            $exitCode = $this->application($stdout, $stderr)->run(['--max-passes=1', $file]);
            $source = (string) file_get_contents($file);

            Assert::same($exitCode, 3);
            Assert::string($source)->contains('$date = new \DateTimeImmutable(\'2026-01-01\');');
            Assert::false(str_contains($source, '$date = $date->modify'));
            Assert::string($stderr)->contains('did not converge within 1 pass(es)');
        } finally {
            @unlink($file);
        }
    }

    public function rejectsInvalidUsageAndShowsHelp(): void
    {
        $stdout = '';
        $stderr = '';
        $application = $this->application($stdout, $stderr);

        Assert::same($application->run([]), 64);
        Assert::string($stderr)->contains('At least one source path is required');

        $stdout = '';
        $stderr = '';

        Assert::same($application->run(['--help']), 0);
        Assert::string($stdout)->contains('Usage: rector-datetime-immutable');
        Assert::same($stderr, '');
    }

    public function composerBinaryBootstrapShowsHelp(): void
    {
        $command = sprintf(
            '%s %s --help 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/rector-datetime-immutable'),
        );

        exec($command, $lines, $exitCode);

        Assert::same($exitCode, 0);
        Assert::string(implode("\n", $lines))->contains('Usage: rector-datetime-immutable');
    }

    private function application(
        string &$stdout,
        string &$stderr,
        ?string $rector = null,
    ): MigrationApplication {
        return new MigrationApplication(
            rectorBinary: $rector ?? dirname(__DIR__) . '/vendor/bin/rector',
            preflightConfig: dirname(__DIR__) . '/config/preflight.php',
            migrationConfig: dirname(__DIR__) . '/config/migration.php',
            reportConfig: dirname(__DIR__) . '/config/report.php',
            acknowledgeConfig: dirname(__DIR__) . '/config/acknowledge.php',
            doctrinePreflightConfig: dirname(__DIR__) . '/config/preflight-doctrine.php',
            doctrineMigrationConfig: dirname(__DIR__) . '/config/migration-doctrine.php',
            stdout: static function (string $message) use (&$stdout): void {
                $stdout .= $message;
            },
            stderr: static function (string $message) use (&$stderr): void {
                $stderr .= $message;
            },
        );
    }

    private function temporaryPhpFile(string $source): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'rector-wrapper-');
        Assert::true(is_string($temporary));
        $path = $temporary . '.php';
        rename($temporary, $path);
        file_put_contents($path, $source);

        return $path;
    }
}
