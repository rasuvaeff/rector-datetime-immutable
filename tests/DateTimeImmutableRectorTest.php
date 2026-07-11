<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * End-to-end: runs the real `rector process` binary over the fixture files —
 * the same path a consumer takes — and compares every produced file with its
 * committed `.expected` counterpart.
 */
#[Test]
#[Covers(DateTimeImmutableRector::class)]
final class DateTimeImmutableRectorTest
{
    #[DataProvider('suiteProvider')]
    public function appliesExpectedTransformations(string $suite, bool $execute = false): void
    {
        FixtureSuite::assertTransformed($suite, __DIR__ . '/config/' . $suite . '.php', execute: $execute);
    }

    public static function suiteProvider(): iterable
    {
        yield 'coherent defaults' => ['default'];
        yield 'explicit full migration' => ['typehints'];
        yield 'typehints only' => ['typehints-only'];
        yield 'properties only' => ['property-only'];
        yield 'allow-subclass opt-in' => ['subclass'];
        yield 'doctrine columns co-migration' => ['doctrine'];
        yield 'default output executes' => ['coherent-default', true];
    }

    public function rejectsUnknownConfigurationKey(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'rector-cfg-') . '.php';
        file_put_contents($configPath, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
            use Rector\Config\RectorConfig;

            return RectorConfig::configure()
                ->withConfiguredRule(DateTimeImmutableRector::class, ['nonsense' => true]);
            PHP);

        $workDir = sys_get_temp_dir() . '/rector-datetime-immutable-invalid-' . bin2hex(random_bytes(4));
        mkdir($workDir, 0o777, true);
        file_put_contents($workDir . '/Sample.php', "<?php\n\$d = new \\DateTime();\n");

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
