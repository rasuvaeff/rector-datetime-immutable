# AGENTS.md — rector-datetime-immutable

Guidance for AI agents working on this package. Read before changing code.

## What this is

Three Rector rules (namespace `Rasuvaeff\RectorDateTimeImmutable`):

- `MutableDateTimeBoundaryRector` — report-only preflight for native, vendor,
  inherited, interface and abstract callables that require mutable `DateTime`;

- `DateTimeImmutableRector` — coherently migrates `DateTime` construction,
  concrete typehints and properties to `DateTimeImmutable` by default;
  individual categories, `extends` rewriting and Doctrine column
  co-migration remain configurable. Also syncs the docblock tags of migrated
  declarations and removes `use DateTime;` imports that lost their last
  reference.
- `LostDateTimeMutationRector` — finds statement-level mutator calls on a
  `DateTimeImmutable` whose result is thrown away; `fix` mode adds the
  assignment, `report` mode attaches a `@todo` marker comment.

Public API: the `vendor/bin/rector-datetime-immutable` convergence command
(flags incl. `--acknowledge-boundaries`, `--doctrine-columns`, `--dry-run`,
`--format=human|github|json`), the three rule classes + their option
constants (`CONSTRUCTORS`/`TYPEHINTS`/`PROPERTIES`/`ALLOW_SUBCLASS`/
`DOCTRINE_COLUMNS`, `MODE`/`MODE_FIX`/`MODE_REPORT`/`MODE_ACKNOWLEDGE`,
boundary `DOCTRINE_COLUMNS`), the `REPORT_MARKER` constants of both report
rules, the `ACKNOWLEDGE_MARKER` constant and the `@mutable-datetime` /
`@mutable-datetime-boundary` markers.
`Internal\{DateTimeMutatorCatalog, FactoryCallMap, DateTimeTypeRewriter,
DirectValueBranches, DocblockTypeRewriter, DoctrineColumnDetector,
MutableDateTimeMarker, MutableDateTimeBoundaryAnalyzer,
UseImportUsageScanner}` are @internal.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. (The psalm.xml
   `PropertyNotSetInConstructor` handler covers ONLY AbstractRector's own
   setter-injected collaborators — a parent-class design we cannot change;
   never widen it.)
3. **Never emit invalid PHP.** Fix mode never assigns to `$this`, `@mixin`
   wrappers or open declared base types; it requires a real immutable subtype
   with the native mutator and closed dispatch (final subtype/union) or a local
   value tracked from an unconditional direct exact built-in
   construction/shared factory. Nested control-flow assignments invalidate
   exactness and never establish it. Mutable property/signature protection is
   destination-specific; it must not suppress unrelated construction in the
   same class or method. Existing immutable names anywhere in a DNF union,
   inherited signatures/properties, ORM columns and `@mutable-datetime`
   declarations are contracts — hands off. Every safety branch needs an e2e
   fixture, and every transformed fixture must pass the harness PHP lint.
   Stable callables whose parameters require mutable `DateTime` are preflight
   blockers; manual migration preserves their connected simple data flow.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Or with Make: `make build`, `make test`, `make mutation`, `make release-check`.

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **Mutation MSI is scoped to `Internal/`.** `DateTimeMutatorCatalog`,
  `FactoryCallMap`, `DateTimeTypeRewriter`, `DoctrineColumnDetector` and
  `MutableDateTimeMarker` are covered in-process. The public rule shells use
  Rector reflection/Scope and are exercised by real subprocess e2e fixtures,
  so they remain excluded from Infection. The reflection-only
  `MutableDateTimeBoundaryAnalyzer` follows the same subprocess coverage path.
  `minMsi = 100` is an Internal-core gate, not a package-wide mutation score.
  Safety decisions left in a rule shell require explicit change/no-change
  fixtures; the harness byte-compares
  output, lints every generated file and executes the coherent-default and
  fix-mode runtime fixtures.
- **The Composer binary is a subprocess e2e shell.** It must run the mandatory
  no-write mutable-boundary preflight, consume Rector's JSON output, stop only
  after a clean confirmation pass, keep diagnostics
  dry-run, preserve exit codes 0/1/2/3/64, print a `hint:` resolution
  line per finding category under each findings list and end with a summary
  line. `--dry-run` copies the paths into a temp workspace, maps every
  reported path back to the original (rector prints cwd-relative paths —
  normalize before matching) and never writes project files;
  `--format=json` suppresses all narration and emits exactly one JSON object
  on stdout; `--acknowledge-boundaries` conflicts with `--dry-run`.
- **The acknowledge marker never silences feed findings.**
  `@mutable-datetime-boundary` on a statement skips only boundary-call
  findings; `parameter $x feeds mutable property $y` keeps blocking because
  silencing it would let the migration break the property assignment.
  `MutableDateTimeMarker` matches `@mutable-datetime` with a word boundary so
  the acknowledge marker is not read as the opt-out tag.
- **Import cleanup errs toward keeping.** `UseImportUsageScanner` counts
  code, docblock and comment references (rector replaces resolvable names
  with FQ nodes — the authored spelling lives in the `originalName`
  attribute); an import is removed only when nothing references its alias.
- **Docblock sync needs runtime evidence.** `DocblockTypeRewriter` runs only
  for the declaration whose native type migrated; docblock-only declarations
  are never rewritten. Only the tag's type token changes.
- **Doctrine co-migration is attributes-only.** String types from the
  datetime/date/time/datetimetz map, `Types::*_MUTABLE` constants or an
  absent `type` argument; positional attribute arguments, dynamic
  expressions, custom types and docblock `@ORM\Column` annotations stay
  preserved. Both rules carry the option — enabling it in the migration but
  not the preflight (or vice versa) desynchronizes feed findings. Its `Cli/` implementation is
  excluded from Infection for the same subprocess-coverage reason as the rule
  shells; real wrapper tests are mandatory.
- **Accessors over preserved mutable properties.** A return type whose
  `return` directly yields a preserved mutable property (ORM column, marked,
  inherited — incl. `??`/ternary branches) never migrates: the runtime value
  stays `DateTime`, a migrated declaration is a guaranteed `TypeError`. The
  registry is filled before method signatures migrate (properties first,
  constructor before other methods — promoted params). Parameters FEEDING a
  preserved property are NOT auto-protected: protecting them breaks call-site
  arguments instead, so the preflight reports them (`parameter $x feeds
  mutable property $y`) and the human marks the method `@mutable-datetime`
  (the stable-boundary machinery then preserves signature + call sites,
  cross-file via reflection docblocks).
- `DateTimeTypeRewriter::rewritten()` mutates nullable/union nodes in place —
  dry-run callers must use the read-only `wouldRewrite()` instead.
- **One rector run cannot both migrate and repair.** Scope types are inferred
  from the pre-migration code, so lost mutations created by
  `DateTimeImmutableRector` are only visible to the *next* run. The e2e
  `migration` suite runs rector twice on purpose; the README documents "run
  until no changes". Do not "fix" this by returning fabricated types.
- **E2E fixtures are `*.php.fixture` / `*.php.expected`** (not `.php`) so
  cs-fixer/psalm/rector of this package never touch them; testo.php excludes
  `tests/fixture` from discovery. Every `.fixture` needs an `.expected`;
  no-change cases commit identical files. The harness runs `php -l` on every
  transformed file and executes the coherent-default and fix-mode fixtures.
  Don't put `setMicrosecond` in fixtures — it exists only on PHP 8.4+, output would be
  version-dependent.
- **Property-test `<test>Generators()` methods must be `public static`**:
  their only call site is property-testing's reflection, so rector's
  `RemoveUnusedPrivateMethodRector` deletes them when private. Public methods
  are safe (no dead-code rule touches them; testo does not treat
  non-void-returning methods as tests) — no rector.php skip needed.
- Keep deterministic unit cases next to property tests for mutation-critical
  lines (e.g. the uppercase entries in the catalog/map providers kill the
  `UnwrapStrToLower` mutants regardless of property-test seeds); requires
  `rasuvaeff/property-testing` ^2.4, where property tests count toward
  per-test coverage.
- Rector internals used knowingly: `ScopeFetcher` and `ReflectionProvider`
  injection are de-facto extension APIs; e2e detects drift when bumping Rector.
- `webmozart/assert` is a direct runtime dependency used by both configurable
  rule shells; never hide it in the require-checker whitelist.
- `rector/rector` is a **runtime** require (the rules execute inside rector)
  and bundles php-parser/phpstan/webmozart — hence the
  composer-require-checker symbol whitelist.
- `getRuleDefinition()` no longer exists in Rector 2.x contracts — do not
  re-add it; the README documents the rules.
- **BC check tolerates only confirmed SKIPPED-only Roave exit 3** (build.yml
  and `make bc-check`): rector/rector's files-only autoload makes Roave report
  `AbstractRector` symbols as `[BC] SKIPPED`. A non-3 process failure, missing
  SKIPPED findings, or any non-SKIPPED `[BC]` line propagates the original exit
  code. Use `make release-check` (not `composer release-check`) — it routes
  through the strict wrapper.
- Code: `declare(strict_types=1)`, `final readonly class` (rule classes are
  `final class` — AbstractRector parent), `#[\Override]`, explicit types.
- **CI workflows are SHA-pinned** (`uses:` → 40-char SHA + `# vN`),
  `permissions: { contents: read }`, `persist-credentials: false`. Verify with
  `zizmor --persona=auditor .github/`.
- `examples/` is part of the public contract: keep scripts runnable.

## When you finish

- Update `README.md` and `llms.txt` (and `examples/` if usage changed);
  update `CHANGELOG.md` when releasing.
- Re-run `composer build`; paste the output. For releases also run
  `make release-check` (includes mutation).
