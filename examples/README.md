# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `01_migrate_construction.sh` | migrates `new \DateTime()` / factories to immutable and prints the diff | no |
| `02_fix_lost_mutations.sh` | full migration: two passes turn a mutable flow into a correct immutable one | no |
| `03_convergence_wrapper.sh` | official wrapper converges automatically and reports one intentional manual case | no |
| `04_mutable_boundary_preflight.sh` | blocks a native `DateTime`-only call before changing source | no |
| `05_doctrine_co_migration.sh` | `--doctrine-columns` co-migrates an ORM column the default run blocks on, previewed with `--dry-run` | no |

With PHP on the host:

```bash
cd examples
sh 01_migrate_construction.sh
sh 02_fix_lost_mutations.sh
sh 03_convergence_wrapper.sh
sh 04_mutable_boundary_preflight.sh
sh 05_doctrine_co_migration.sh
```

Without local PHP — run inside the `composer:2` image (from the package root):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh examples/01_migrate_construction.sh
docker run --rm -v "$PWD":/app -w /app composer:2 sh examples/02_fix_lost_mutations.sh
docker run --rm -v "$PWD":/app -w /app composer:2 sh examples/03_convergence_wrapper.sh
docker run --rm -v "$PWD":/app -w /app composer:2 sh examples/04_mutable_boundary_preflight.sh
docker run --rm -v "$PWD":/app -w /app composer:2 sh examples/05_doctrine_co_migration.sh
```

The scripts use `vendor/bin/rector` or the package wrapper from this package's dev install
(`make install` first).
