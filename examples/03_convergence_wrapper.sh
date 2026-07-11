#!/bin/sh
# Runs the official convergence wrapper and accepts the expected manual-review
# exit code from an intentionally open DateTimeImmutable parameter.
set -eu
DIR="$(mktemp -d)"
trap 'rm -rf "$DIR"' EXIT

cat > "$DIR/Sample.php" <<'PHP'
<?php

$deadline = new \DateTime('2026-01-01');
$deadline->modify('+1 month');

function moveOpenDate(\DateTimeImmutable $date): void
{
    $date->modify('+1 day');
}

echo $deadline->format('Y-m-d'), PHP_EOL;
PHP

WRAPPER="$(dirname "$0")/../bin/rector-datetime-immutable"
CODE=0
"$WRAPPER" "$DIR/Sample.php" || CODE=$?

if [ "$CODE" -ne 2 ]; then
    echo "Expected manual-review exit code 2, got $CODE" >&2
    exit 1
fi

echo '--- converged source ---'
cat "$DIR/Sample.php"

echo '--- runtime result ---'
php "$DIR/Sample.php"
