#!/bin/sh
# Shows Doctrine column co-migration: by default the preflight blocks on a
# constructor parameter feeding a mutable ORM column; with --doctrine-columns
# the column mapping, property, accessor and parameter migrate together —
# previewed here with --dry-run, so no source file changes.
set -eu
DIR="$(mktemp -d)"
trap 'rm -rf "$DIR"' EXIT

cat > "$DIR/Subscription.php" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Subscription
{
    #[ORM\Column(type: 'datetime')]
    private DateTime $expiresAt;

    public function __construct(DateTime $expiresAt)
    {
        $this->expiresAt = $expiresAt;
    }

    public function getExpiresAt(): DateTime
    {
        return $this->expiresAt;
    }
}
PHP

cp "$DIR/Subscription.php" "$DIR/Subscription.before.php"
WRAPPER="$(dirname "$0")/../bin/rector-datetime-immutable"

echo '--- default run: the preflight blocks on the preserved ORM column ---'
CODE=0
"$WRAPPER" "$DIR/Subscription.php" || CODE=$?

if [ "$CODE" -ne 2 ]; then
    echo "Expected preflight exit code 2, got $CODE" >&2
    exit 1
fi

if ! cmp -s "$DIR/Subscription.before.php" "$DIR/Subscription.php"; then
    echo 'Preflight changed the source file' >&2
    exit 1
fi

echo '--- co-migration preview: --doctrine-columns --dry-run ---'
OUT="$("$WRAPPER" --doctrine-columns --dry-run "$DIR/Subscription.php")"
printf '%s\n' "$OUT"

if ! printf '%s' "$OUT" | grep -q "type: 'datetime_immutable'"; then
    echo 'Expected the diff to move the column mapping to datetime_immutable' >&2
    exit 1
fi

if ! printf '%s' "$OUT" | grep -q 'DateTimeImmutable \$expiresAt'; then
    echo 'Expected the diff to migrate the constructor parameter' >&2
    exit 1
fi

if ! cmp -s "$DIR/Subscription.before.php" "$DIR/Subscription.php"; then
    echo 'Dry run changed the source file' >&2
    exit 1
fi

echo 'Doctrine co-migration previewed without changing source.'
