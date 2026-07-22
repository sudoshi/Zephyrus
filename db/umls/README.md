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

The orchestrator will support:

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

- UMLS `integer` to PostgreSQL `bigint`
- `numeric(5,2)` unchanged
- `char(n)` to `varchar(n)`
- string declarations of 1,000 characters or longer to `text`

Each RRF row ends in `|`. Tables initially include `_rrf_trailer text CHECK (_rrf_trailer IS NULL)`, allowing direct client-side CSV `\copy` without rewriting source files. Finalization drops that column after all files load.

## Recovery

The loader commits one file per transaction. A failed `COPY` rolls back its `TRUNCATE`, data, and status update. Re-running `load` skips only files whose stored source digest and loaded row count match the generated manifest.

Do not delete or recreate the database to recover a single failed file. Inspect `db/umls/generated/logs`, correct the input/environment issue, and rerun `load`.

