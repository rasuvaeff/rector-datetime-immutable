#!/bin/sh
# Proves that the wrapper reports a DateTime-only native boundary before making
# any source change.
set -eu
DIR="$(mktemp -d)"
trap 'rm -rf "$DIR"' EXIT

cat > "$DIR/Sample.php" <<'PHP'
<?php

$date = new \DateTime('2026-01-01');
date_modify($date, '+1 day');
PHP

cp "$DIR/Sample.php" "$DIR/Sample.before.php"
WRAPPER="$(dirname "$0")/../bin/rector-datetime-immutable"
CODE=0
"$WRAPPER" "$DIR/Sample.php" || CODE=$?

if [ "$CODE" -ne 2 ]; then
    echo "Expected preflight exit code 2, got $CODE" >&2
    exit 1
fi

if ! cmp -s "$DIR/Sample.before.php" "$DIR/Sample.php"; then
    echo 'Preflight changed the source file' >&2
    exit 1
fi

echo 'Preflight blocked migration without changing source.'
