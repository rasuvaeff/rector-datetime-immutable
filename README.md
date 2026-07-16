# rasuvaeff/rector-datetime-immutable

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/rector-datetime-immutable.svg)](https://packagist.org/packages/rasuvaeff/rector-datetime-immutable)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/rector-datetime-immutable.svg)](https://packagist.org/packages/rasuvaeff/rector-datetime-immutable)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/rector-datetime-immutable/build.yml?branch=master)](https://github.com/rasuvaeff/rector-datetime-immutable/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/rector-datetime-immutable/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/rector-datetime-immutable/actions)
[![Psalm Level](https://img.shields.io/badge/Psalm-level%201-brightgreen.svg)](psalm.xml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/rector-datetime-immutable/php)](https://packagist.org/packages/rasuvaeff/rector-datetime-immutable)
[![License](https://img.shields.io/packagist/l/rasuvaeff/rector-datetime-immutable.svg)](LICENSE.md)

[Русская версия](README.ru.md)

[Rector](https://getrector.com) rules that migrate mutable `DateTime` to
`DateTimeImmutable` — and **auto-fix the lost mutations** the migration
creates, the classic silent bug where `$date->modify('+1 day');` throws the
new instance away:

```php
// before — mutable construction, in-place mutation
$deadline = new \DateTime('2026-01-01');
$deadline->modify('+1 month');

// after (both rules) — immutable, and the mutation result is kept
$deadline = new \DateTimeImmutable('2026-01-01');
$deadline = $deadline->modify('+1 month');
```

PHPStan (level 4) and Psalm *report* ignored `DateTimeImmutable` mutator
results; this package is the piece that **fixes them in bulk** during a
migration.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact reference
> you can pass as context.

## TL;DR

Two ways to run the migration:

| Path | How |
|---|---|
| **CLI wrapper** (recommended) | `vendor/bin/rector-datetime-immutable src` — boundary preflight, migration to convergence and a diagnostic pass in one command; see [One-command migration](#one-command-migration) |
| **Manual `rector.php`** | register the rules yourself; see [Manual Rector setup](#manual-rector-setup) |

**Manual setup warning:** one Rector run cannot both migrate and repair — run
`vendor/bin/rector process` **until it reports no changes** (usually twice),
otherwise the lost mutations created by the first pass stay in the code. The
wrapper does this for you.

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [One-command migration](#one-command-migration)
  - [Dry-run preview](#dry-run-preview)
  - [CI output](#ci-output)
  - [Resolving preflight findings](#resolving-preflight-findings)
  - [Doctrine columns co-migration](#doctrine-columns-co-migration)
  - [Manual Rector setup](#manual-rector-setup)
  - [`MutableDateTimeBoundaryRector`](#mutabledatetimeboundaryrector)
  - [`DateTimeImmutableRector`](#datetimeimmutablerector)
  - [`LostDateTimeMutationRector`](#lostdatetimemutationrector)
  - [Markers](#markers)
- [Security](#security)
- [Examples](#examples)
- [Development](#development)
- [License](#license)

## Requirements

- PHP 8.3 - 8.5 to run the rules
- `rector/rector` ^2.5
- `webmozart/assert` ^1.11 || ^2.0
- `proc_open` enabled when using the convergence wrapper — available in a
  default PHP build unless the host disables it via `disable_functions`

## Installation

```bash
composer require --dev rasuvaeff/rector-datetime-immutable
```

## Usage

### One-command migration

The installed Composer binary first runs a read-only mutable-boundary preflight,
applies the default migration repeatedly until a clean confirmation pass, then
runs `LostDateTimeMutationRector` in `MODE_REPORT` without changing the files:

```bash
vendor/bin/rector-datetime-immutable src
```

The command edits the selected paths. Commit or stash unrelated work first.
Typical output:

```text
Preflight: no mutable DateTime boundaries found.
Migration pass 1: 12 changed file(s).
Migration pass 2: 4 changed file(s).
Migration pass 3: 0 changed file(s).
Converged after 2 change-producing pass(es).
Diagnostic pass: no manual review cases found.
Summary: 14 file(s) changed across 2 change-producing pass(es); 0 manual review case(s).
```

If preflight finds a native, inherited, abstract/interface or vendor callable
whose parameter accepts `DateTime` but rejects `DateTimeImmutable`, or a method
parameter that feeds a property the migration preserves as mutable, it prints
`file:line` entries plus a resolution hint per finding category, exits with
code `2` and changes no files. The same exit is used after convergence when the
lost-mutation report finds a case that cannot be assigned safely.

| Exit | Meaning |
|---|---|
| `0` | migration converged and no manual cases remain |
| `1` | Rector/process/JSON failure |
| `2` | preflight blocked migration or post-migration manual review remains |
| `3` | migration did not converge within the pass limit |
| `64` | invalid wrapper arguments |

Useful options:

```bash
vendor/bin/rector-datetime-immutable --dry-run src           # full preview, no writes
vendor/bin/rector-datetime-immutable --acknowledge-boundaries src
vendor/bin/rector-datetime-immutable --doctrine-columns src  # co-migrate ORM columns
vendor/bin/rector-datetime-immutable --format=github src     # or --format=json
vendor/bin/rector-datetime-immutable --max-passes=8 src tests
vendor/bin/rector-datetime-immutable --no-report src
vendor/bin/rector-datetime-immutable \
    --preflight-config=rector-preflight.php \
    --config=rector-migration.php \
    --report-config=rector-report.php \
    src
```

The packaged defaults are `config/preflight.php`, `config/migration.php` and
`config/report.php`. Use custom configs for project-specific skips, staged
options or `ALLOW_SUBCLASS`.

### Dry-run preview

`--dry-run` copies the paths into a temporary workspace, runs the whole flow
there — preflight, convergence, diagnostic pass — prints every would-be diff
with paths mapped back to the originals and changes no project file. Exit
codes keep their meaning, so the preview also tells you how the real run
would end. Declarations outside the copied paths (vendor classes, parents in
directories you did not pass) are still read from their original files; the
write run remains authoritative.

### CI output

`--format=github` keeps the human output and additionally emits
`::error file=…,line=…::…` workflow annotations for preflight blockers and
`::warning …` for manual review cases, so the migration PR shows every finding
inline.

`--format=json` suppresses narration and prints a single machine-readable
object on stdout: `status` (`clean`, `blocked`, `manual-review`,
`not-converged`, `acknowledged`), `exitCode`, per-pass `passes`,
`changedFiles`, and the `preflight`/`manualReview`/`acknowledged` findings as
`{file, line, message, category}` where `category` is one of
`requires-datetime`, `feeds-mutable-property`, `lost-mutation`, `diagnostic`.
With `--dry-run` the object also carries the would-be `diffs`.

### Resolving preflight findings

| Finding | Resolution |
|---|---|
| `parameter $x feeds mutable property $y` | mark the enclosing method `@mutable-datetime` — its signature and connected call-site arguments stay mutable — co-migrate ORM columns with `--doctrine-columns`, or migrate the storage contract first |
| `parameter $x requires DateTime` | rewrite the call to a `DateTimeImmutable`-safe API, or review the flow and acknowledge it |

`@mutable-datetime` on the **calling** method does not silence a
`requires DateTime` finding: the marker preserves that method's own contract,
while the finding points at the called native/vendor/inherited parameter. The
migration itself keeps values connected to such a callable by simple
assignments mutable, so once the flow is reviewed, acknowledge it:

```bash
vendor/bin/rector-datetime-immutable --acknowledge-boundaries src
```

This writes a self-documenting comment above every boundary call and re-runs
the preflight:

```php
// @mutable-datetime-boundary: parameter $object requires DateTime
date_modify($moment, '+1 hour');
```

A statement carrying `@mutable-datetime-boundary` is skipped by all further
preflights — the review lives in the code and survives reruns. Findings of
the `feeds mutable property` kind are **never** auto-acknowledged: silencing
them would let the migration break the property assignment at runtime, so
they keep their own resolutions above.

File-level skipping through a custom preflight config remains available as
the coarse alternative:

```php
// rector-preflight.php
<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\MutableDateTimeBoundaryRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([
        MutableDateTimeBoundaryRector::class,
    ])
    ->withSkip([
        MutableDateTimeBoundaryRector::class => [
            __DIR__ . '/src/Legacy/SdkAdapter.php',
        ],
    ]);
```

```bash
vendor/bin/rector-datetime-immutable --preflight-config=rector-preflight.php src
```

After convergence the same exit `2` reports lost mutations the fix mode cannot
assign safely — resolve those by assigning the mutator result yourself
(`$date = $date->modify(...)`).

### Doctrine columns co-migration

By default ORM-mapped members are preserved. `--doctrine-columns` (the
`DOCTRINE_COLUMNS` option of both rules) opts into co-migrating
attribute-mapped columns: the property, its accessors and connected
constructor parameters migrate together with the mapping, which moves to the
native immutable DBAL variant — same database schema, immutable hydration.

```php
#[ORM\Column(type: 'datetime')]              // → type: 'datetime_immutable'
private \DateTime $expiresAt;                // → private \DateTimeImmutable $expiresAt;

#[ORM\Column(type: Types::DATETIME_MUTABLE)] // → Types::DATETIME_IMMUTABLE
#[ORM\Column]                                // no type: Doctrine infers it from the PHP type
```

Covered mappings: `datetime`, `date`, `time`, `datetimetz` as string literals
or the matching `Types::*_MUTABLE` constants, plus columns without a `type`
argument. Custom type strings, dynamic type expressions, positional attribute
arguments and docblock `@ORM\Column` annotations stay preserved. Requires
`doctrine/dbal` ≥ 2.6 (native `*_immutable` types). Review lifecycle code
that mutated entity dates in place — the diagnostic pass reports it as lost
mutations.

### Manual Rector setup

```php
// rector.php
<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Rasuvaeff\RectorDateTimeImmutable\LostDateTimeMutationRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withRules([
        DateTimeImmutableRector::class,
        LostDateTimeMutationRector::class,
    ]);
```

Without the wrapper, run `vendor/bin/rector process` **until it reports no
changes** (usually twice): within one run type inference still sees the
pre-migration types, so lost mutations created by the construction migration
become visible to the *next* run.

Run `MutableDateTimeBoundaryRector` separately with `--dry-run` before a manual
migration. Do not combine it with migration rules: its comments are diagnostic
diff markers, not source changes to commit.

### `MutableDateTimeBoundaryRector`

Reports arguments flowing into stable callables whose declared parameter
accepts `DateTime` but rejects `DateTimeImmutable`. Stable callables are native
PHP functions, vendor functions/methods, interface or abstract methods, and
methods constrained by an ancestor or marked `@mutable-datetime`. Other local
concrete callables are not reported because their declarations migrate together
with their call sites.

The analysis supports positional, named and variadic arguments across
functions, instance/static methods and constructors. The CLI runs this rule as
a mandatory dry-run before changing files.

The rule also reports method parameters that feed a property the migration
preserves as mutable — ORM columns, `@mutable-datetime` declarations,
inherited properties (`$this->ormColumn = $param;`, including `??`/ternary
branches): migrating such a parameter guarantees a `TypeError` on the property
assignment. Resolve by marking the method `@mutable-datetime` (its signature
and connected call-site arguments then stay mutable), by co-migrating ORM
columns with `--doctrine-columns`, or by migrating the storage contract
itself.

Statements carrying the `@mutable-datetime-boundary` comment are skipped as
already-reviewed boundary calls. The rule's `MODE` option selects `report`
(default — attach `@todo` diff markers) or `acknowledge` (write the
`@mutable-datetime-boundary` comment above every boundary call; used by the
CLI's `--acknowledge-boundaries`). Feed findings are never written in
acknowledge mode.

### `DateTimeImmutableRector`

Migrates `DateTime` construction and concrete type declarations to
`DateTimeImmutable`.

| Option | Default | What it enables |
|---|---|---|
| `CONSTRUCTORS` | `true` | `new \DateTime(...)`, shared static factories including `createFromTimestamp()`, and the two procedural `date_create*()` factories |
| `TYPEHINTS` | `true` | `\DateTime` in named functions, methods, closures, arrow functions and enum methods (incl. nullable and union types) |
| `PROPERTIES` | `true` | `\DateTime` in typed properties and promoted constructor parameters |
| `ALLOW_SUBCLASS` | `false` | rewrite `class X extends \DateTime` to `extends \DateTimeImmutable` (risky — downstream in-place mutation breaks; pair with `LostDateTimeMutationRector`) |
| `DOCTRINE_COLUMNS` | `false` | co-migrate attribute-mapped Doctrine columns together with their mapping type (see «Doctrine columns co-migration») |

The migration also keeps the file consistent:

- the `@var`/`@param`/`@return` docblock types of a migrated declaration
  (including the `@psalm-`/`@phpstan-` tag variants) are rewritten to
  `DateTimeImmutable` — only the type token changes, descriptions stay;
  docblock-only declarations without a native type are never rewritten;
- a `use DateTime;` import (aliased or not) is removed once nothing in the
  file references it anymore — code, docblock and comment references all
  count, and the scanner errs toward keeping the import.

```php
->withConfiguredRule(DateTimeImmutableRector::class, [
    DateTimeImmutableRector::CONSTRUCTORS => true,
    DateTimeImmutableRector::TYPEHINTS => true,
    DateTimeImmutableRector::PROPERTIES => true,
    DateTimeImmutableRector::ALLOW_SUBCLASS => false,
])
```

Explicitly disabling one category is supported for staged migrations, but an
intermediate stage may not be executable until related construction and type
declarations are migrated. Run static analysis and tests after every stage.

Never touched:

| Case | Why |
|---|---|
| `class X extends \DateTime` (without `ALLOW_SUBCLASS`) | rewriting the parent breaks the subclass's in-place mutation |
| Signatures and properties declared by an ancestor/interface/trait | implementations must preserve inherited contracts |
| Interfaces, traits, abstract classes | their signatures are contracts for implementations |
| `#[Column]` / `@ORM\Column` mapped members | the ORM decides the concrete class per mapped type |
| Anything whose docblock carries `@mutable-datetime` | explicit opt-out marker |
| `\DateTime::createFromImmutable(...)` | has no `DateTimeImmutable` counterpart; its containing return type also stays mutable |
| Construction inside anonymous/abstract/trait scopes, plus defaults, direct property assignments and returns feeding preserved mutable contracts | prevents an immutable value from being injected into a skipped declaration without blocking unrelated migrations in the same class/method |
| Values connected by simple assignments to a stable `DateTime`-only callable such as `date_modify()` or a vendor API | related parameters, properties, returns and construction stay mutable |
| Unions already containing `\DateTimeImmutable`, including inside a DNF intersection | rewriting could create a duplicate or redundant type |
| Return types whose `return` directly yields a preserved mutable property, incl. `??`/ternary branches | the runtime value stays `DateTime`; the migrated declaration would be a guaranteed `TypeError` |
| Docblock types on declarations without a migrated native type | a docblock-only contract carries no runtime evidence; tags on migrated declarations are synced automatically |
| `new $class()`, intersection types | not statically provable |

### `LostDateTimeMutationRector`

Finds statement-level mutator calls on a `DateTimeImmutable` whose return
value is thrown away: `modify`, `add`, `sub`, `setDate`, `setTime`,
`setISODate`, `setTimezone`, `setTimestamp`, `setMicrosecond`.

| Mode | Behaviour |
|---|---|
| `MODE_FIX` (default) | rewrites `$d->modify(...);` to `$d = $d->modify(...);` for directly initialized exact built-in variables and final subclasses/unions; never assigns to `$this` |
| `MODE_REPORT` | attaches a `// @todo lost DateTimeImmutable mutation…` marker comment instead; run with `--dry-run` to fail CI while leaving code untouched |

```php
->withConfiguredRule(LostDateTimeMutationRector::class, [
    LostDateTimeMutationRector::MODE => LostDateTimeMutationRector::MODE_REPORT,
])
```

Skipped in both modes: used results, mutable receivers, non-subtypes (including
PHPStan `@mixin` wrappers), and statically visible mutator overrides. Fix mode
also skips `$this`, property/call receivers, open declared types such as a
`DateTimeImmutable` parameter, and locals populated by an open return type: a
runtime subclass can override the mutator and legally mutate in place. A local
becomes exact only after an unconditional top-level assignment from direct
built-in construction, a shared static factory, a procedural
`date_create_immutable*()` factory, a `clone` of an exact value, or another
proven exact expression. A plain alias (`$b = $a;`) deliberately does not
establish exactness: in the pre-migration mutable program both names shared
one mutated object, so a receiver-only assignment could silently diverge from
legacy behaviour — such statements stay reported.
Assignments nested in conditionals, loops, switch/try/match branches and
short-circuit expressions never establish exactness and invalidate an open
proof conservatively. Therefore an assignment and lost mutation contained in
the same conditional branch may intentionally remain unchanged. Final
subclasses and unions of final subclasses are safe to fix. Report mode may flag
an open subtype diagnostically because it does not modify the program. Nullsafe
calls (`$d?->modify(...)`) are out of scope.

`MODE_REPORT` overlaps with PHPStan level 4 ("call on a separate line has no
effect") — use it only if your pipeline runs Rector without a static analyzer.

### Markers

Add `@mutable-datetime` to a docblock to keep a declaration mutable on
purpose:

```php
/**
 * @mutable-datetime — third-party SDK mutates this in place
 */
private \DateTime $sdkClock;
```

Add `@mutable-datetime-boundary` as a comment on a call statement to mark a
reviewed boundary call — the preflight then skips it. `--acknowledge-boundaries`
writes these comments for you:

```php
// @mutable-datetime-boundary: parameter $object requires DateTime
date_modify($moment, '+1 hour');
```

## Security

This is a contract-changing migration. The defaults migrate construction and
concrete local declarations together. Typed native/vendor/inherited callable
boundaries, inherited properties/signatures, ORM mappings and dynamic names are
guarded. Dynamic calls, magic dispatch, reflection and untyped external data
flows cannot be proven by a source-to-source rule. Review the diff and run the
full project build after every pass, especially when using staged options or
`ALLOW_SUBCLASS`, which intentionally changes runtime behaviour of `DateTime`
subclasses.

## Examples

Runnable scripts live in [`examples/`](examples/README.md).

## Development

```bash
make install   # composer install (Docker, no local PHP needed)
make build     # validate + normalize + require-checker + cs + psalm + tests
make test      # testo (unit + e2e fixtures)
make mutation  # infection, minMsi=100 — gates the Internal/ decision core;
               # the rule shells run inside rector subprocesses and are
               # covered by the e2e fixture suites instead
```

Mutation testing is scoped to `src/Internal/` by design: the decision core
(mutator catalog, factory map, type/docblock rewriters, Doctrine column
detector, marker matcher) runs in-process and is gated at `minMsi = 100`. The
public rule classes and the CLI execute inside Rector subprocesses, which
Infection cannot observe — they are covered by the e2e fixture suites instead
(byte-compared output, `php -l` on every transformed file, executed runtime
fixtures). The Infection numbers therefore certify the `Internal/` core, not
a package-wide mutation score; see [AGENTS.md](AGENTS.md) for the rationale.

## License

[BSD-3-Clause](LICENSE.md)
