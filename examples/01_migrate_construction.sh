#!/bin/sh
# Applies DateTimeImmutableRector to a throwaway sample and shows the diff.
set -eu
DIR="$(mktemp -d)"
trap 'rm -rf "$DIR"' EXIT

cat > "$DIR/Sample.php" <<'PHP'
<?php

$startedAt = new \DateTime('2026-01-01');
$parsed = \DateTime::createFromFormat('Y-m-d', '2026-01-01');
$procedural = date_create('yesterday');
PHP

cat > "$DIR/rector.php" <<'PHP'
<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([DateTimeImmutableRector::class]);
PHP

# --dry-run exits 2 when it finds a diff — that is the expected outcome here
"$(dirname "$0")/../vendor/bin/rector" process "$DIR/Sample.php" --config "$DIR/rector.php" --dry-run --clear-cache || [ $? -eq 2 ]
