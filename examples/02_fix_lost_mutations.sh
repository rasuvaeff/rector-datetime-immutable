#!/bin/sh
# Full migration flow: pass 1 migrates construction, pass 2 repairs the lost
# mutation the migration created. Prints the file after each pass.
set -eu
DIR="$(mktemp -d)"
trap 'rm -rf "$DIR"' EXIT

cat > "$DIR/Sample.php" <<'PHP'
<?php

$deadline = new \DateTime('2026-01-01');
$deadline->modify('+1 month');

echo $deadline->format('Y-m-d'), PHP_EOL;
PHP

cat > "$DIR/rector.php" <<'PHP'
<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Rasuvaeff\RectorDateTimeImmutable\LostDateTimeMutationRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([
        DateTimeImmutableRector::class,
        LostDateTimeMutationRector::class,
    ]);
PHP

RECTOR="$(dirname "$0")/../vendor/bin/rector"

echo '--- pass 1 (construction migrated) ---'
"$RECTOR" process "$DIR/Sample.php" --config "$DIR/rector.php" --no-progress-bar --no-diffs --clear-cache >/dev/null
cat "$DIR/Sample.php"

echo '--- pass 2 (lost mutation repaired) ---'
"$RECTOR" process "$DIR/Sample.php" --config "$DIR/rector.php" --no-progress-bar --no-diffs --clear-cache >/dev/null
cat "$DIR/Sample.php"
