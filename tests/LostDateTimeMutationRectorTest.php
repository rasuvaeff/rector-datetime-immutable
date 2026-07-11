<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Rasuvaeff\RectorDateTimeImmutable\LostDateTimeMutationRector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * End-to-end: runs the real `rector process` binary over the fixture files.
 * The `migration` suite runs both rules together — the intended consumer
 * setup, where migrated construction and the lost mutations it creates are
 * handled in one pass.
 */
#[Test]
#[Covers(LostDateTimeMutationRector::class)]
#[Covers(DateTimeImmutableRector::class)]
final class LostDateTimeMutationRectorTest
{
    #[DataProvider('suiteProvider')]
    public function appliesExpectedTransformations(string $suite, int $passes, bool $execute = false): void
    {
        FixtureSuite::assertTransformed(
            $suite,
            __DIR__ . '/config/' . $suite . '.php',
            $passes,
            execute: $execute,
        );
    }

    public static function suiteProvider(): iterable
    {
        yield 'fix mode (default)' => ['lost-mutation', 1, true];
        yield 'report mode' => ['lost-mutation-report', 1];
        yield 'combined migration (two passes)' => ['migration', 2];
    }

    public function rejectsInvalidMode(): void
    {
        $configPath = sys_get_temp_dir() . '/rector-cfg-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($configPath, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Rasuvaeff\RectorDateTimeImmutable\LostDateTimeMutationRector;
            use Rector\Config\RectorConfig;

            return RectorConfig::configure()
                ->withConfiguredRule(LostDateTimeMutationRector::class, [
                    LostDateTimeMutationRector::MODE => 'nonsense',
                ]);
            PHP);

        $workDir = sys_get_temp_dir() . '/rector-datetime-immutable-invalid-' . bin2hex(random_bytes(4));
        mkdir($workDir, 0o777, true);
        file_put_contents($workDir . '/Sample.php', "<?php\n\$d = new \\DateTimeImmutable();\n\$d->modify('+1 day');\n");

        try {
            [$exitCode, $output] = FixtureSuite::runRector($workDir, $configPath);

            Assert::true($exitCode !== 0, 'invalid configuration must fail the run');
            Assert::string($output)->contains('nonsense');
        } finally {
            @unlink($workDir . '/Sample.php');
            @rmdir($workDir);
            @unlink($configPath);
        }
    }
}
