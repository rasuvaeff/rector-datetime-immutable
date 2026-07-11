<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Cli;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use JsonException;
use Rasuvaeff\RectorDateTimeImmutable\LostDateTimeMutationRector;
use Rasuvaeff\RectorDateTimeImmutable\MutableDateTimeBoundaryRector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * @internal
 *
 * @psalm-type Finding = array{file: string, line: null|int, message: string, category: string}
 * @psalm-type Options = array{
 *     paths: list<string>,
 *     maxPasses: int,
 *     preflightConfig: string,
 *     config: string,
 *     reportConfig: string,
 *     rector: string,
 *     report: bool,
 *     help: bool,
 *     acknowledge: bool,
 *     dryRun: bool,
 *     doctrine: bool,
 *     format: string,
 *     pathMap: list<array{temp: string, original: string}>
 * }
 */
final readonly class MigrationApplication
{
    private const int EXIT_SUCCESS = 0;
    private const int EXIT_FAILURE = 1;
    private const int EXIT_MANUAL_REVIEW = 2;
    private const int EXIT_NOT_CONVERGED = 3;
    private const int EXIT_USAGE = 64;
    private const int DEFAULT_MAX_PASSES = 5;
    private const int MAX_PASSES_LIMIT = 100;

    private const string FORMAT_HUMAN = 'human';
    private const string FORMAT_GITHUB = 'github';
    private const string FORMAT_JSON = 'json';

    private const array FORMATS = [
        self::FORMAT_HUMAN,
        self::FORMAT_GITHUB,
        self::FORMAT_JSON,
    ];

    /**
     * Only the markers this package's report rules emit count as findings —
     * a generic `@todo` in user code on an added diff line must not.
     */
    private const array REPORT_MARKERS = [
        MutableDateTimeBoundaryRector::REPORT_MARKER,
        LostDateTimeMutationRector::REPORT_MARKER,
    ];

    /** @var Closure(string): void */
    private Closure $stdout;

    /** @var Closure(string): void */
    private Closure $stderr;

    /**
     * @param null|Closure(string): void $stdout
     * @param null|Closure(string): void $stderr
     */
    public function __construct(
        private string $rectorBinary,
        private string $preflightConfig,
        private string $migrationConfig,
        private string $reportConfig,
        private string $acknowledgeConfig,
        private string $doctrinePreflightConfig,
        private string $doctrineMigrationConfig,
        ?Closure $stdout = null,
        ?Closure $stderr = null,
    ) {
        $this->stdout = $stdout ?? static function (string $message): void {
            fwrite(STDOUT, $message);
        };
        $this->stderr = $stderr ?? static function (string $message): void {
            fwrite(STDERR, $message);
        };
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        try {
            $options = $this->parseArguments($arguments);

            if ($options['help']) {
                $this->write($this->usage());

                return self::EXIT_SUCCESS;
            }

            if ($options['doctrine']) {
                if ($options['preflightConfig'] === $this->preflightConfig) {
                    $options['preflightConfig'] = $this->doctrinePreflightConfig;
                }

                if ($options['config'] === $this->migrationConfig) {
                    $options['config'] = $this->doctrineMigrationConfig;
                }
            }

            $this->validateOptions($options);

            if ($options['dryRun']) {
                return $this->migrateWorkspaceCopy($options);
            }

            return $options['acknowledge'] ? $this->acknowledgeBoundaries($options) : $this->migrate($options);
        } catch (InvalidArgumentException $exception) {
            $this->writeError('Usage error: ' . $exception->getMessage() . "\n\n" . $this->usage());

            return self::EXIT_USAGE;
        } catch (Throwable $throwable) {
            $this->writeError('Migration failed: ' . $throwable->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return Options
     */
    private function parseArguments(array $arguments): array
    {
        $paths = [];
        $maxPasses = self::DEFAULT_MAX_PASSES;
        $preflightConfig = $this->preflightConfig;
        $config = $this->migrationConfig;
        $reportConfig = $this->reportConfig;
        $rector = $this->rectorBinary;
        $report = true;
        $help = false;
        $acknowledge = false;
        $dryRun = false;
        $doctrine = false;
        $format = self::FORMAT_HUMAN;

        for ($index = 0, $count = count($arguments); $index < $count; ++$index) {
            $argument = $arguments[$index];

            if ($argument === '--') {
                foreach (array_slice($arguments, $index + 1) as $path) {
                    $paths[] = $path;
                }

                break;
            }

            if ($argument === '--help' || $argument === '-h') {
                $help = true;

                continue;
            }

            if ($argument === '--no-report') {
                $report = false;

                continue;
            }

            if ($argument === '--acknowledge-boundaries') {
                $acknowledge = true;

                continue;
            }

            if ($argument === '--dry-run') {
                $dryRun = true;

                continue;
            }

            if ($argument === '--doctrine-columns') {
                $doctrine = true;

                continue;
            }

            if (in_array($argument, ['--max-passes', '--preflight-config', '--config', '--report-config', '--rector', '--format'], true)
            ) {
                ++$index;

                if (!isset($arguments[$index])) {
                    throw new InvalidArgumentException(sprintf('Option "%s" requires a value', $argument));
                }

                $this->assignOption(
                    $argument,
                    $arguments[$index],
                    $maxPasses,
                    $preflightConfig,
                    $config,
                    $reportConfig,
                    $rector,
                    $format,
                );

                continue;
            }

            $matchedOption = false;

            foreach (['--max-passes', '--preflight-config', '--config', '--report-config', '--rector', '--format'] as $option) {
                $prefix = $option . '=';

                if (!str_starts_with($argument, $prefix)) {
                    continue;
                }

                $this->assignOption(
                    $option,
                    substr($argument, strlen($prefix)),
                    $maxPasses,
                    $preflightConfig,
                    $config,
                    $reportConfig,
                    $rector,
                    $format,
                );
                $matchedOption = true;

                break;
            }

            if ($matchedOption) {
                continue;
            }

            if (str_starts_with($argument, '-')) {
                throw new InvalidArgumentException(sprintf('Unknown option "%s"', $argument));
            }

            $paths[] = $argument;
        }

        return [
            'paths' => $paths,
            'maxPasses' => $maxPasses,
            'preflightConfig' => $preflightConfig,
            'config' => $config,
            'reportConfig' => $reportConfig,
            'rector' => $rector,
            'report' => $report,
            'help' => $help,
            'acknowledge' => $acknowledge,
            'dryRun' => $dryRun,
            'doctrine' => $doctrine,
            'format' => $format,
            'pathMap' => [],
        ];
    }

    private function assignOption(
        string $option,
        string $value,
        int &$maxPasses,
        string &$preflightConfig,
        string &$config,
        string &$reportConfig,
        string &$rector,
        string &$format,
    ): void {
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Option "%s" requires a non-empty value', $option));
        }

        if ($option === '--max-passes') {
            if (preg_match('/^[0-9]+$/D', $value) !== 1) {
                throw new InvalidArgumentException('--max-passes must be an integer between 1 and 100');
            }

            $maxPasses = (int) $value;

            if ($maxPasses < 1 || $maxPasses > self::MAX_PASSES_LIMIT) {
                throw new InvalidArgumentException('--max-passes must be an integer between 1 and 100');
            }

            return;
        }

        if ($option === '--format') {
            if (!in_array($value, self::FORMATS, true)) {
                throw new InvalidArgumentException('--format must be one of: human, github, json');
            }

            $format = $value;

            return;
        }

        if ($option === '--config') {
            $config = $value;

            return;
        }

        if ($option === '--preflight-config') {
            $preflightConfig = $value;

            return;
        }

        if ($option === '--report-config') {
            $reportConfig = $value;

            return;
        }

        $rector = $value;
    }

    /**
     * @param Options $options
     */
    private function validateOptions(array $options): void
    {
        if ($options['paths'] === []) {
            throw new InvalidArgumentException('At least one source path is required');
        }

        if ($options['acknowledge'] && $options['dryRun']) {
            throw new InvalidArgumentException('Options "--acknowledge-boundaries" and "--dry-run" cannot be combined');
        }

        if (!is_file($options['rector'])) {
            throw new InvalidArgumentException(sprintf('Rector binary "%s" does not exist', $options['rector']));
        }

        if (!is_file($options['preflightConfig'])) {
            throw new InvalidArgumentException(sprintf('Preflight config "%s" does not exist', $options['preflightConfig']));
        }

        if (!is_file($options['config'])) {
            throw new InvalidArgumentException(sprintf('Migration config "%s" does not exist', $options['config']));
        }

        if ($options['report'] && !is_file($options['reportConfig'])) {
            throw new InvalidArgumentException(sprintf('Report config "%s" does not exist', $options['reportConfig']));
        }

        if ($options['acknowledge'] && !is_file($this->acknowledgeConfig)) {
            throw new InvalidArgumentException(sprintf('Acknowledge config "%s" does not exist', $this->acknowledgeConfig));
        }

        foreach ($options['paths'] as $path) {
            if (!file_exists($path)) {
                throw new InvalidArgumentException(sprintf('Source path "%s" does not exist', $path));
            }
        }
    }

    /**
     * Runs the full migration flow against a temporary copy of the source
     * paths: the project files are never written, every reported path maps
     * back to the original location. Declarations outside the copied paths
     * (vendor classes, parents living elsewhere) are still read from their
     * original files, so the write run remains authoritative.
     *
     * @param Options $options
     */
    private function migrateWorkspaceCopy(array $options): int
    {
        $workspace = $this->createWorkspace($options['paths']);
        $options['paths'] = $workspace['paths'];
        $options['pathMap'] = $workspace['map'];

        try {
            return $this->migrate($options);
        } finally {
            $this->removeDirectory($workspace['root']);
        }
    }

    /**
     * Writes the acknowledge marker above every reviewed boundary call, then
     * re-runs the read-only preflight so remaining findings (parameters
     * feeding preserved mutable properties are never auto-acknowledged) keep
     * blocking with exit code 2.
     *
     * @param Options $options
     */
    private function acknowledgeBoundaries(array $options): int
    {
        $result = $this->invokeRector(
            rector: $options['rector'],
            paths: $options['paths'],
            config: $this->acknowledgeConfig,
            dryRun: false,
        );
        $acknowledged = [];

        if ($result['changedFiles'] === 0) {
            $this->narrate($options, "No boundary calls to acknowledge.\n");
        } else {
            $acknowledged = $this->extractFindings(
                $options,
                $result['diffs'],
                [MutableDateTimeBoundaryRector::ACKNOWLEDGE_MARKER],
            );
            $this->narrate($options, sprintf("Acknowledged %d boundary call(s):\n", \count($acknowledged)));
            $this->printFindings($options, $acknowledged, errorStream: false);
        }

        $preflight = $this->preflight($options);
        $this->narrate($options, sprintf(
            "Summary: %d boundary call(s) acknowledged; %d preflight finding(s) remain.\n",
            \count($acknowledged),
            \count($preflight['findings']),
        ));

        if ($preflight['exit'] === self::EXIT_SUCCESS) {
            $this->narrate($options, "Re-run without --acknowledge-boundaries to migrate.\n");
        }

        $this->emitJson($options, [
            'status' => $preflight['exit'] === self::EXIT_SUCCESS ? 'acknowledged' : 'blocked',
            'exitCode' => $preflight['exit'],
            'dryRun' => false,
            'acknowledged' => $acknowledged,
            'preflight' => $preflight['findings'],
            'passes' => [],
            'changedFiles' => [],
            'manualReview' => [],
        ]);

        return $preflight['exit'];
    }

    /**
     * @param Options $options
     */
    private function migrate(array $options): int
    {
        $preflight = $this->preflight($options);

        if ($preflight['exit'] !== self::EXIT_SUCCESS) {
            $this->emitJson($options, [
                'status' => 'blocked',
                'exitCode' => $preflight['exit'],
                'dryRun' => $options['dryRun'],
                'preflight' => $preflight['findings'],
                'passes' => [],
                'changedFiles' => [],
                'manualReview' => [],
            ]);

            return $preflight['exit'];
        }

        $changedPasses = 0;
        $passes = [];
        $changedFiles = [];
        $dryRunDiffs = [];

        for ($pass = 1; $pass <= $options['maxPasses']; ++$pass) {
            $result = $this->invokeRector(
                rector: $options['rector'],
                paths: $options['paths'],
                config: $options['config'],
                dryRun: false,
            );

            $this->narrate($options, sprintf(
                "Migration pass %d: %d changed file(s).\n",
                $pass,
                $result['changedFiles'],
            ));
            $passes[] = ['pass' => $pass, 'changedFiles' => $result['changedFiles']];

            foreach ($result['diffs'] as $diff) {
                $displayPath = $this->displayPath($options, $diff['file']);
                $changedFiles[$displayPath] = true;

                if ($options['dryRun']) {
                    $dryRunDiffs[] = ['file' => $displayPath, 'diff' => $diff['diff']];
                    $this->narrate($options, sprintf(
                        "Would change %s:\n%s\n",
                        $displayPath,
                        rtrim($diff['diff'], "\n"),
                    ));
                }
            }

            if ($result['changedFiles'] === 0) {
                $this->narrate($options, sprintf(
                    "Converged after %d change-producing pass(es).\n",
                    $changedPasses,
                ));

                $manualReview = [];
                $exitCode = self::EXIT_SUCCESS;

                if ($options['report']) {
                    $reportResult = $this->report($options);
                    $manualReview = $reportResult['findings'];
                    $exitCode = $reportResult['exit'];
                } else {
                    $this->narrate($options, "Diagnostic pass disabled.\n");
                }

                $this->narrate($options, sprintf(
                    "Summary: %d file(s) changed across %d change-producing pass(es); %d manual review case(s).\n",
                    \count($changedFiles),
                    $changedPasses,
                    \count($manualReview),
                ));

                if ($options['dryRun']) {
                    $this->narrate($options, "Dry-run: no project files were changed.\n");
                }

                $payload = [
                    'status' => $exitCode === self::EXIT_SUCCESS ? 'clean' : 'manual-review',
                    'exitCode' => $exitCode,
                    'dryRun' => $options['dryRun'],
                    'preflight' => [],
                    'passes' => $passes,
                    'changedFiles' => array_keys($changedFiles),
                    'manualReview' => $manualReview,
                ];

                if ($options['dryRun']) {
                    $payload['diffs'] = $dryRunDiffs;
                }

                $this->emitJson($options, $payload);

                return $exitCode;
            }

            ++$changedPasses;
        }

        $this->narrateError($options, sprintf(
            "Migration did not converge within %d pass(es); diagnostic pass was not run.\n",
            $options['maxPasses'],
        ));
        $this->emitJson($options, [
            'status' => 'not-converged',
            'exitCode' => self::EXIT_NOT_CONVERGED,
            'dryRun' => $options['dryRun'],
            'preflight' => [],
            'passes' => $passes,
            'changedFiles' => array_keys($changedFiles),
            'manualReview' => [],
        ]);

        return self::EXIT_NOT_CONVERGED;
    }

    /**
     * @param Options $options
     *
     * @return array{exit: int, findings: list<Finding>}
     */
    private function preflight(array $options): array
    {
        $result = $this->invokeRector(
            rector: $options['rector'],
            paths: $options['paths'],
            config: $options['preflightConfig'],
            dryRun: true,
        );

        if ($result['changedFiles'] === 0) {
            $this->narrate($options, "Preflight: no mutable DateTime boundaries found.\n");

            return ['exit' => self::EXIT_SUCCESS, 'findings' => []];
        }

        $findings = $this->extractFindings($options, $result['diffs']);
        $this->narrateError($options, sprintf(
            "Migration blocked before changing files: %d mutable DateTime boundary case(s).\n",
            count($findings),
        ));
        $this->printFindings($options, $findings, errorStream: true);

        foreach ($this->boundaryHints($findings) as $hint) {
            $this->narrateError($options, '  hint: ' . $hint . "\n");
        }

        $this->emitGithubAnnotations($options, $findings, 'error');

        return ['exit' => self::EXIT_MANUAL_REVIEW, 'findings' => $findings];
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function boundaryHints(array $findings): array
    {
        $hints = [];

        foreach ($findings as $finding) {
            if (str_contains($finding['message'], 'feeds mutable property')) {
                $hints['feeds'] = 'mark the enclosing method @mutable-datetime, co-migrate ORM columns with --doctrine-columns, or migrate the storage contract first.';

                continue;
            }

            if (str_contains($finding['message'], 'requires DateTime')) {
                $hints['requires'] = 'rewrite the call for DateTimeImmutable, or review the flow and run --acknowledge-boundaries (@mutable-datetime on the calling method does not silence this).';
            }
        }

        return array_values($hints);
    }

    /**
     * @param Options $options
     *
     * @return array{exit: int, findings: list<Finding>}
     */
    private function report(array $options): array
    {
        $result = $this->invokeRector(
            rector: $options['rector'],
            paths: $options['paths'],
            config: $options['reportConfig'],
            dryRun: true,
        );

        if ($result['changedFiles'] === 0) {
            $this->narrate($options, "Diagnostic pass: no manual review cases found.\n");

            return ['exit' => self::EXIT_SUCCESS, 'findings' => []];
        }

        $findings = $this->extractFindings($options, $result['diffs']);
        $this->narrateError($options, sprintf("Manual review required: %d case(s).\n", count($findings)));
        $this->printFindings($options, $findings, errorStream: true);

        foreach ($findings as $finding) {
            if (str_contains($finding['message'], LostDateTimeMutationRector::REPORT_MARKER)) {
                $this->narrateError($options, "  hint: assign the mutator result yourself (\$date = \$date->modify(...)); receivers the rule cannot prove exact are reported instead of auto-fixed.\n");

                break;
            }
        }

        $this->emitGithubAnnotations($options, $findings, 'warning');

        return ['exit' => self::EXIT_MANUAL_REVIEW, 'findings' => $findings];
    }

    /**
     * @param Options $options
     * @param list<Finding> $findings
     */
    private function printFindings(array $options, array $findings, bool $errorStream): void
    {
        foreach ($findings as $finding) {
            $location = $finding['line'] === null
                ? $finding['file']
                : $finding['file'] . ':' . $finding['line'];
            $line = sprintf("  %s  %s\n", $location, $finding['message']);

            if ($errorStream) {
                $this->narrateError($options, $line);
            } else {
                $this->narrate($options, $line);
            }
        }
    }

    /**
     * @param Options $options
     * @param list<array{file: string, diff: string}> $diffs
     * @param list<string> $markers
     *
     * @return list<Finding>
     */
    private function extractFindings(array $options, array $diffs, array $markers = self::REPORT_MARKERS): array
    {
        $findings = [];

        foreach ($diffs as $diff) {
            $newLine = null;
            $foundInFile = false;
            $lines = preg_split('/\R/', $diff['diff']) ?: [];

            foreach ($lines as $line) {
                if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $matches) === 1) {
                    $newLine = (int) $matches[1];

                    continue;
                }

                if ($newLine === null || str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                    continue;
                }

                if (str_starts_with($line, '+')) {
                    if ($this->containsReportMarker($line, $markers)) {
                        $message = trim(substr($line, 1));
                        $findings[] = [
                            'file' => $this->displayPath($options, $diff['file']),
                            'line' => $newLine,
                            'message' => $message,
                            'category' => $this->findingCategory($message),
                        ];
                        $foundInFile = true;
                    }

                    ++$newLine;

                    continue;
                }

                if (!str_starts_with($line, '-') && !str_starts_with($line, '\\')) {
                    ++$newLine;
                }
            }

            if (!$foundInFile) {
                $findings[] = [
                    'file' => $this->displayPath($options, $diff['file']),
                    'line' => null,
                    'message' => 'Rector reported a diagnostic diff',
                    'category' => 'diagnostic',
                ];
            }
        }

        return $findings;
    }

    private function findingCategory(string $message): string
    {
        if (str_contains($message, 'feeds mutable property')) {
            return 'feeds-mutable-property';
        }

        if (str_contains($message, 'requires DateTime')) {
            return 'requires-datetime';
        }

        if (str_contains($message, LostDateTimeMutationRector::REPORT_MARKER)) {
            return 'lost-mutation';
        }

        return 'diagnostic';
    }

    /**
     * @param list<string> $markers
     */
    private function containsReportMarker(string $line, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($line, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths
     *
     * @return array{root: string, paths: list<string>, map: list<array{temp: string, original: string}>}
     */
    private function createWorkspace(array $paths): array
    {
        $root = sys_get_temp_dir() . '/rector-datetime-immutable-dry-' . bin2hex(random_bytes(6));

        if (!mkdir($root, 0o777, true)) {
            throw new RuntimeException(sprintf('Unable to create the dry-run workspace "%s"', $root));
        }

        $tempPaths = [];
        $map = [];

        foreach ($paths as $index => $path) {
            $realPath = realpath($path);

            if ($realPath === false) {
                throw new RuntimeException(sprintf('Unable to resolve source path "%s"', $path));
            }

            $target = $root . '/p' . $index . '/' . basename($realPath);
            $this->copyPath($realPath, $target);
            $realTarget = realpath($target);

            if ($realTarget === false) {
                throw new RuntimeException(sprintf('Unable to copy "%s" into the dry-run workspace', $path));
            }

            $tempPaths[] = $realTarget;
            $map[] = ['temp' => $realTarget, 'original' => $path];
        }

        return ['root' => $root, 'paths' => $tempPaths, 'map' => $map];
    }

    private function copyPath(string $source, string $target): void
    {
        $targetDirectory = is_file($source) ? \dirname($target) : $target;

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0o777, true)) {
            throw new RuntimeException(sprintf('Unable to create the dry-run directory "%s"', $targetDirectory));
        }

        if (is_file($source)) {
            if (!copy($source, $target)) {
                throw new RuntimeException(sprintf('Unable to copy "%s" into the dry-run workspace', $source));
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $destination = $target . substr($item->getPathname(), \strlen($source));

            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0o777, true)) {
                    throw new RuntimeException(sprintf('Unable to create the dry-run directory "%s"', $destination));
                }

                continue;
            }

            if (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException(sprintf('Unable to copy "%s" into the dry-run workspace', $item->getPathname()));
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    /**
     * Rector reports paths relative to its working directory — normalize to
     * an absolute path before mapping a dry-run workspace file back to the
     * original location.
     *
     * @param Options $options
     */
    private function displayPath(array $options, string $path): string
    {
        if ($options['pathMap'] === []) {
            return $path;
        }

        $absolute = str_starts_with($path, '/') ? $path : (getcwd() ?: '.') . '/' . $path;
        $normalized = realpath($absolute);
        $candidate = $normalized === false ? $absolute : $normalized;

        foreach ($options['pathMap'] as $mapping) {
            if ($candidate === $mapping['temp']) {
                return $mapping['original'];
            }

            if (str_starts_with($candidate, $mapping['temp'] . '/')) {
                return $mapping['original'] . substr($candidate, \strlen($mapping['temp']));
            }
        }

        return $path;
    }

    /**
     * @param Options $options
     */
    private function narrate(array $options, string $message): void
    {
        if ($options['format'] !== self::FORMAT_JSON) {
            $this->write($message);
        }
    }

    /**
     * @param Options $options
     */
    private function narrateError(array $options, string $message): void
    {
        if ($options['format'] !== self::FORMAT_JSON) {
            $this->writeError($message);
        }
    }

    /**
     * @param Options $options
     * @param array<string, mixed> $payload
     */
    private function emitJson(array $options, array $payload): void
    {
        if ($options['format'] !== self::FORMAT_JSON) {
            return;
        }

        $this->write(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ) . "\n");
    }

    /**
     * @param Options $options
     * @param list<Finding> $findings
     */
    private function emitGithubAnnotations(array $options, array $findings, string $level): void
    {
        if ($options['format'] !== self::FORMAT_GITHUB) {
            return;
        }

        foreach ($findings as $finding) {
            $this->write(sprintf(
                "::%s file=%s,line=%d,title=rector-datetime-immutable::%s\n",
                $level,
                $this->githubProperty($finding['file']),
                $finding['line'] ?? 1,
                $this->githubData($finding['message']),
            ));
        }
    }

    private function githubData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function githubProperty(string $value): string
    {
        return str_replace([',', ':'], ['%2C', '%3A'], $this->githubData($value));
    }

    /**
     * @param list<string> $paths
     *
     * @return array{changedFiles: int, diffs: list<array{file: string, diff: string}>}
     */
    private function invokeRector(
        string $rector,
        array $paths,
        string $config,
        bool $dryRun,
    ): array {
        $command = [PHP_BINARY, $rector, 'process', ...$paths];
        $command[] = '--config';
        $command[] = $config;
        $command[] = '--output-format=json';
        $command[] = '--no-progress-bar';
        $command[] = '--clear-cache';
        $command[] = '--no-ansi';

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $process = $this->runProcess($command);

        if ($process['stderr'] !== '') {
            $this->writeError($process['stderr']);
        }

        $acceptedExitCodes = $dryRun ? [0, 2] : [0];

        if (!in_array($process['exitCode'], $acceptedExitCodes, true)) {
            throw new RuntimeException(sprintf(
                "Rector exited with code %d.\n%s",
                $process['exitCode'],
                trim($process['stdout']),
            ));
        }

        $result = $this->decodeRectorOutput($process['stdout']);

        if ($result['errors'] !== 0) {
            throw new RuntimeException(sprintf('Rector reported %d error(s)', $result['errors']));
        }

        if ($dryRun && (($result['changedFiles'] > 0) !== ($process['exitCode'] === 2))) {
            throw new RuntimeException('Rector dry-run exit code and JSON totals disagree');
        }

        return [
            'changedFiles' => $result['changedFiles'],
            'diffs' => $result['diffs'],
        ];
    }

    /**
     * @param list<string> $command
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command): array
    {
        $errorStream = tmpfile();

        if ($errorStream === false) {
            throw new RuntimeException('Unable to create a temporary stderr stream');
        }

        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => $errorStream,
            ],
            $pipes,
            options: ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            fclose($errorStream);

            throw new RuntimeException('Unable to start Rector');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        rewind($errorStream);
        $stderr = stream_get_contents($errorStream);
        fclose($errorStream);

        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Unable to read Rector process output');
        }

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @return array{
     *     changedFiles: int,
     *     errors: int,
     *     diffs: list<array{file: string, diff: string}>
     * }
     */
    private function decodeRectorOutput(string $output): array
    {
        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Rector did not return valid JSON', $exception->getCode(), previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Rector JSON root must be an object');
        }

        $totals = $decoded['totals'] ?? null;
        $rawDiffs = $decoded['file_diffs'] ?? [];

        if (!is_array($totals) || !is_array($rawDiffs)) {
            throw new RuntimeException('Rector JSON is missing totals or has invalid file_diffs');
        }

        $changedFiles = $totals['changed_files'] ?? null;
        $errors = $totals['errors'] ?? null;

        if (!is_int($changedFiles) || !is_int($errors)) {
            throw new RuntimeException('Rector JSON totals have invalid types');
        }

        $diffs = [];

        foreach ($rawDiffs as $rawDiff) {
            if (!is_array($rawDiff)) {
                throw new RuntimeException('Rector JSON contains an invalid file diff');
            }

            $file = $rawDiff['file'] ?? null;
            $diff = $rawDiff['diff'] ?? null;

            if (!is_string($file) || !is_string($diff)) {
                throw new RuntimeException('Rector JSON file diff has invalid types');
            }

            $diffs[] = ['file' => $file, 'diff' => $diff];
        }

        return [
            'changedFiles' => $changedFiles,
            'errors' => $errors,
            'diffs' => $diffs,
        ];
    }

    private function usage(): string
    {
        return <<<'TEXT'
            Usage: rector-datetime-immutable [options] <path> [<path>...]

            Runs a no-write mutable-boundary preflight, applies the migration
            until Rector reports no changes, then runs a report-only pass.

            Options:
              --max-passes=N       Maximum passes including the clean confirmation (default: 5)
              --preflight-config=FILE Mutable-boundary preflight config (default: package config)
              --config=FILE        Migration Rector config (default: package config)
              --report-config=FILE Diagnostic Rector config (default: package config)
              --rector=FILE        Rector binary (default: sibling vendor/bin/rector)
              --no-report          Skip the final diagnostic pass
              --acknowledge-boundaries
                                   Write a @mutable-datetime-boundary comment above
                                   every reviewed boundary call instead of migrating,
                                   then re-run the preflight
              --dry-run            Run the full flow against a temporary copy of the
                                   paths, print the would-be diffs and change nothing
              --doctrine-columns   Co-migrate attribute-mapped Doctrine columns: the
                                   Column type moves to its *_immutable DBAL variant
                                   together with the PHP type (custom types and
                                   docblock annotations stay preserved)
              --format=FORMAT      Output format: human (default), github (adds
                                   ::error/::warning annotations), json (single
                                   machine-readable object on stdout)
              -h, --help           Show this help

            Exit codes: 0 clean, 1 process failure, 2 manual review required,
            3 did not converge, 64 invalid usage.
            TEXT . "\n";
    }

    private function write(string $message): void
    {
        ($this->stdout)($message);
    }

    private function writeError(string $message): void
    {
        ($this->stderr)($message);
    }
}
