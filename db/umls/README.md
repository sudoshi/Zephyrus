# UMLS 2026AA PostgreSQL Import

This directory contains the reproducible PostgreSQL 17 import kit for a licensed UMLS Metathesaurus Rich Release Format directory. It does not contain and must never receive UMLS data files.

The current release contract is:

- source directory: `/home/smudoshi/Downloads/Datasets/2026AA/META`
- database: `umls_2026aa`
- schema: `umls`
- PostgreSQL client: `/usr/lib/postgresql/17/bin/psql`

The governing checklist is [`docs/superpowers/plans/2026-07-22-umls-2026aa-postgresql-import-TODO.md`](../../docs/superpowers/plans/2026-07-22-umls-2026aa-postgresql-import-TODO.md).

## Safety and licensing

UMLS content is licensed. Do not copy RRF rows, generated extracts, dumps, logs containing rows, or credentials into Git. Generated manifests contain filenames, counts, sizes, and hashes only and are ignored by default.

The import creates a dedicated database. It is not a Laravel migration and is not run by `deploy.sh`.

## Commands

The orchestrator supports:

```bash
db/umls/umls-import prepare
db/umls/umls-import source-validate
db/umls/umls-import bootstrap
db/umls/umls-import schema
db/umls/umls-import load
db/umls/umls-import finalize
db/umls/umls-import index
db/umls/umls-import validate
db/umls/umls-import status
db/umls/umls-import all
```

Override defaults without editing tracked files:

```bash
UMLS_META_DIR=/path/to/META \
UMLS_DATABASE=umls_2026aa \
db/umls/umls-import source-validate
```

Authentication uses the local operator's PostgreSQL peer identity and `SET ROLE`; passwords and connection URLs are not stored by this package.

## Import representation

`MRFILES.RRF` supplies filenames, column order, row counts, and declared sizes. `MRCOLS.RRF` supplies types, nullability evidence, and observed length controls. The generator maps:

- UMLS `integer` to PostgreSQL `bigint`, except lexical fixed-width values such
  as `MRRANK.RANK`, which must preserve its leading zeroes as `varchar(4)`
- `numeric(5,2)` unchanged
- `char(n)` to `varchar(n)`
- string declarations of 1,000 characters or longer to `text`

Each RRF row ends in `|`. Tables initially include `_rrf_trailer text CHECK (_rrf_trailer IS NULL)`, allowing direct client-side CSV `\copy` without rewriting source files. Finalization drops that column after all files load.

## Recovery

The loader commits one file per transaction. A failed `COPY` rolls back its `TRUNCATE`, data, and status update. Re-running `load` skips only files whose stored source digest and loaded row count match the generated manifest.

Do not delete or recreate the database to recover a single failed file. Inspect
`db/umls/generated/2026AA/logs`, correct the input/environment issue, and rerun
`load`.

## Validation

`validate` runs two release-derived gates. `04-validate.sql` checks the manifest,
all 56 row counts, total cardinality, key distributions, semantic examples,
indexes, statistics, and role boundaries. `05-lengths.sql` performs a null-aware
comparison of the minimum, average, and maximum length of all 359 release
columns against `MRCOLS.RRF`. Either gate fails the command on a discrepancy.

Generated evidence remains ignored under `db/umls/generated/`; commit only the
tooling and a non-licensed summary such as the governing checklist or evidence
README.

## Backup and restore proof

The host physical base-backup and WAL archive cover the entire PostgreSQL
cluster, including this database. The existing application logical-backup job
does not cover UMLS, so a release must also receive an explicit custom-format
logical backup after validation:

```bash
/usr/lib/postgresql/17/bin/pg_dump \
  --format=custom \
  --compress=zstd:3 \
  --file=/protected/backup/path/umls_2026aa-YYYYMMDD-HHMMSS.dump \
  umls_2026aa

/usr/lib/postgresql/17/bin/pg_restore \
  --list \
  /protected/backup/path/umls_2026aa-YYYYMMDD-HHMMSS.dump
```

A TOC listing is only an artifact check. Before handoff, restore the dump into
an isolated database, generate the validation package with that scratch
database name, and run both validators:

```bash
UMLS_DATABASE=umls_2026aa_restore_verify db/umls/bootstrap.sh

/usr/lib/postgresql/17/bin/pg_restore \
  --exit-on-error \
  --dbname=umls_2026aa_restore_verify \
  /protected/backup/path/umls_2026aa-YYYYMMDD-HHMMSS.dump

validation_dir="$(mktemp -d -p /tmp umls-restore-validation-XXXXXXXX)"
UMLS_DATABASE=umls_2026aa_restore_verify \
UMLS_GENERATED_DIR="$validation_dir" \
db/umls/umls-import prepare
UMLS_DATABASE=umls_2026aa_restore_verify \
UMLS_GENERATED_DIR="$validation_dir" \
db/umls/umls-import validate
```

Recheck the exact scratch database name and retain non-licensed evidence before
dropping it. Never use the live release database as the restore target.

## Future release replacement

Treat every UMLS release as immutable. For `2026AB`, use a new source directory
and database such as `umls_2026ab`; do not load it over `umls_2026aa`.

1. Run `prepare` and `source-validate` with the new source, output directory,
   and database overrides.
2. Review release-driven type changes and generated SQL before bootstrap.
3. Run `bootstrap`, `schema`, `load`, `finalize`, `index`, and `validate`.
4. Produce and restore-test a release-named logical backup.
5. Switch approved consumers only after the new database passes its gates.
6. Retain the previous database and backup until rollback retention is approved;
   dropping an old release is a separate destructive operation.

## Optional Zephyrus connection

This import does not add an application connection. If a Zephyrus use case is
approved, add a separately named read-only PostgreSQL connection using
environment-provided credentials for a dedicated login role that is only a
member of `umls_reader`. Do not make `umls_reader` a login, reuse the loader or
owner role, add UMLS to Laravel migrations, or expose unbounded corpus queries
directly to browser-controlled input.
