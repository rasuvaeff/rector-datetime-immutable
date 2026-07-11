<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Tests;

use Rasuvaeff\RectorDateTimeImmutable\MutableDateTimeBoundaryRector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MutableDateTimeBoundaryRector::class)]
final class MutableDateTimeBoundaryRectorTest
{
    public function reportsStableMutableOnlyCalls(): void
    {
        FixtureSuite::assertTransformed('boundary-report', __DIR__ . '/config/boundary-report.php');
    }

    public function acknowledgesReviewedBoundaryCalls(): void
    {
        FixtureSuite::assertTransformed('boundary-acknowledge', __DIR__ . '/config/boundary-acknowledge.php');
    }

    public function rejectsInvalidMode(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'rector-cfg-') . '.php';
        file_put_contents($configPath, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Rasuvaeff\RectorDateTimeImmutable\MutableDateTimeBoundaryRector;
            use Rector\Config\RectorConfig;

            return RectorConfig::configure()
                ->withConfiguredRule(MutableDateTimeBoundaryRector::class, [
                    MutableDateTimeBoundaryRector::MODE => 'nonsense',
                ]);
            PHP);

        $workDir = sys_get_temp_dir() . '/rector-datetime-immutable-mode-' . bin2hex(random_bytes(4));
        mkdir($workDir, 0o777, true);
        file_put_contents($workDir . '/Sample.php', "<?php\ndate_modify(new \\DateTime(), '+1 day');\n");

        try {
            [$exitCode, $output] = FixtureSuite::runRector($workDir, $configPath);

            Assert::true($exitCode !== 0, 'invalid mode must fail the run');
            Assert::string($output)->contains('nonsense');
        } finally {
            @unlink($workDir . '/Sample.php');
            @rmdir($workDir);
            @unlink($configPath);
        }
    }
}
