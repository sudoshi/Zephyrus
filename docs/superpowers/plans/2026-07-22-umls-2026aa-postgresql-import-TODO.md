# UMLS 2026AA PostgreSQL 17 Import — Implementation TODO

**Started:** 2026-07-22

**Release:** UMLS Metathesaurus `2026AA`, Level 0 subset

**Source:** `/home/smudoshi/Downloads/Datasets/2026AA/META`

**Target:** PostgreSQL 17 database `umls_2026aa`, schema `umls`

**Convention:** Check an item off only after the implementation and its named evidence exist. A successful command is not sufficient when the item requires data, catalog, query, backup, or restore proof.

Legend: `[x]` complete · `[~]` implementation exists but final evidence is incomplete · `[ ]` open

---

## Non-negotiable boundaries

- [x] Keep the licensed UMLS RRF contents outside Git and outside application deployment artifacts.
- [x] Use a dedicated PostgreSQL database instead of silently adding the corpus to `zephyrus`.
- [x] Use unquoted lowercase `umls` and table/column identifiers so ordinary `UMLS.MRCONSO` SQL resolves without quoted-identifier friction.
- [x] Treat `MRFILES.RRF` and `MRCOLS.RRF` as the release manifest and schema authorities.
- [x] Treat bundled Oracle/MySQL SQL, control files, and scripts as compatibility references only.
- [x] Do not import MetamorphoSys `.x` byte-offset indexes as relational tables.
- [x] Do not create unmanifested `MRCXT` merely because the legacy SQL mentions it.
- [x] Preserve UTF-8 data, empty-field-to-NULL semantics, long values, and the final RRF trailer field without preprocessing the licensed files.
- [x] Do not change cluster-wide durability settings, restart PostgreSQL, pause backups, or modify backup schedules without a separately evidenced need and explicit authorization.
- [x] Keep the loader resumable at a single-file boundary; never require a successful 392-million-row reload merely to recover one failed file.
- [x] Keep UMLS access limited to explicit owner, loader, and reader roles and verify that `PUBLIC` has no database/schema access.

---

## Authoritative source controls

- [x] Inventory all 56 RRF files, 50 Oracle control files, 7 MetamorphoSys `.x` files, 4 SQL files, 4 shell/batch loaders, and release/configuration metadata.
- [x] Confirm `2026AA`, Base Release for Spring 2026, release date `2026-05-04`.
- [x] Confirm 56 RRF files and 392,875,930 expected rows.
- [x] Confirm 32,889,841,451 declared RRF bytes (30.63 GiB).
- [x] Confirm 7,778,895,358 bytes (7.24 GiB) of `.x` locator indexes are excluded from import.
- [x] Confirm every RRF is valid UTF-8.
- [x] Confirm every nonempty RRF has its declared column count plus exactly one trailing empty field.
- [x] Confirm every RRF row ends with the required `|` trailer.
- [x] Confirm no RRF contains control-A, the planned PostgreSQL CSV quote/escape sentinel.
- [x] Confirm all declared row counts match the physical files.
- [x] Record the isolated `MRDOC.RRF` byte discrepancy: 234,752 declared characters versus 234,754 UTF-8 bytes because of one en dash.
- [x] Confirm the six RRF tables omitted by legacy loaders: `MRXW_ARA`, `MRXW_ISL`, `MRXW_LIT`, `MRXW_SLK`, `MRXW_SLV`, and `MRXW_UKR`.
- [x] Confirm `MRHIST`, `DELETEDLUI`, `DELETEDSUI`, and `MERGEDLUI` are intentionally empty release files.
- [x] Confirm release maxima that invalidate the legacy 4,000-character DDL: `MRSAT.ATV=35,985` and `MRDEF.DEF=10,992`.
- [x] Generate SHA-256 for every RRF and deterministic release-manifest digest `06e31698b61a0e347d8dc9b2d5b7ccbff44ec898eabe3e3d5c229ba2d96c99a8`.
- [x] Persist source controls in the target database before loading data.
- [x] Re-run the source validator from the checked-in tooling after the lexical-rank correction; all 56 files passed in ignored evidence log `source-validation-20260722-195239.log`.

---

## Phase 0 — Import package and operating contract

### 0.1 Version-controlled tooling

- [x] Add `db/umls/README.md` with supported commands, recovery behavior, type policy, security boundary, and licensing warning.
- [x] Add an ignored generated-artifact area for manifests, SQL, state, and logs.
- [x] Add a manifest/DDL generator driven by the release's `MRFILES.RRF` and `MRCOLS.RRF`.
- [x] Add a full source validator for UTF-8, row count, field count, RRF trailer, control-A absence, file size, and SHA-256 identity.
- [x] Add idempotent PostgreSQL role/database/schema bootstrap tooling.
- [x] Add a resumable client-side `\copy` loader pinned to PostgreSQL 17.
- [x] Add post-load finalization, constraints, indexes, statistics, and validation SQL generation.
- [x] Add explicit commands for `prepare`, `source-validate`, `bootstrap`, `schema`, `load`, `finalize`, `index`, `validate`, `status`, and `all`.
- [x] Make every command fail closed with `ON_ERROR_STOP`, nonzero exit status, and a visible file/table context.
- [x] Ensure no password, connection URL, licensed row, or local secret is written to Git.

### 0.2 PostgreSQL type contract

- [x] Map UMLS `integer` to PostgreSQL `bigint` unless the release carries a lexical fixed-width contract; generated `MRFILES.BTS` safely holds 9,922,733,803-byte files and `MRRANK.RANK` preserves `0947`…`0001` as `varchar(4)`.
- [x] Map `numeric(5,2)` without loss.
- [x] Map Oracle-style `char(n)` to `varchar(n)` to avoid PostgreSQL blank-padding behavior.
- [x] Map fields declared at 1,000 characters or longer to `text`.
- [x] Preserve `CVF` as character data rather than coercing it to integer.
- [x] Create a checked-null `_rrf_trailer` column for the final RRF delimiter during load.
- [x] Apply `NOT NULL` only after the release has loaded and current-release non-null evidence is known.
- [x] Add primary/unique constraints only after bulk loading.
- [x] Avoid broad foreign keys across polymorphic and historical UMLS identifiers.

**Phase 0 exit gate:** A clean checkout can regenerate an identical manifest and SQL package from this 2026AA directory without reading or modifying application credentials.

---

## Phase 1 — Host and database bootstrap

### 1.1 Live host preflight

- [x] Confirm PostgreSQL server `17.10` is online on `127.0.0.1:5432`.
- [x] Confirm version-matched client `/usr/lib/postgresql/17/bin/psql` exists.
- [x] Confirm server encoding is UTF-8 and `C.utf8` plus ICU `und-x-icu` collations are available.
- [x] Confirm `umls_2026aa` does not already exist before implementation.
- [x] Confirm 32 CPUs, 123 GiB RAM, 793 GiB database-filesystem headroom, and 659 GiB backup-filesystem headroom.
- [x] Confirm WAL archiving is enabled and active, no replication slot retains WAL, and no base backup is currently active.
- [x] Confirm the logical backup job is limited to Parthenon `app` and will not automatically dump the UMLS database.
- [x] Confirm physical backups and WAL archives will include the new database.
- [x] Confirm the `postgres` OS account cannot read the mode-0600 RRF files, requiring client-side `\copy`.
- [x] Recheck free space, archiver health, active backups, and competing long PostgreSQL work immediately before the first bulk load; the Parthenon refresh cleared before loading began.
- [x] Reserve at least 200 GiB on `/` and 150 GiB on `/mnt/md0` through the import and independently verified recovery test; pre-load headroom was 783 GiB and 658 GiB respectively.

### 1.2 Roles, database, and schema

- [x] Create non-login role `umls_owner`.
- [x] Create non-login role `umls_loader` and grant it only the required load/audit privileges.
- [x] Create non-login role `umls_reader` and grant it read-only privileges.
- [x] Grant the local operator membership needed to assume loader/reader roles without embedding passwords.
- [x] Create database `umls_2026aa` from `template0`, UTF-8, `C.utf8`, owned by `umls_owner`.
- [x] Revoke default database access from `PUBLIC`.
- [x] Create schema `umls`, owned by `umls_owner`.
- [x] Revoke schema access and creation from `PUBLIC`.
- [x] Configure default privileges so future UMLS tables remain readable only by `umls_reader` and writable only by the loader/owner boundary.
- [x] Create release and file-level audit tables before any RRF table.
- [x] Prove role boundaries with positive loader/reader tests and negative `PUBLIC` tests.

**Phase 1 exit gate:** The empty database exists with the intended locale and least-privilege roles, and its audit tables contain the exact source manifest.

---

## Phase 2 — Table creation and data load

### 2.1 Create the 56-table release schema

- [x] Generate all 56 data tables from `MRFILES`/`MRCOLS`.
- [x] Create all six language tables omitted by the legacy scripts.
- [x] Exclude `MRCXT` and all seven `.x` files.
- [x] Confirm the generated column order exactly matches each `MRFILES.FMT` declaration.
- [x] Confirm long fields resolve to `text`, identifiers to non-padding strings, and numeric metadata to non-overflowing types.
- [x] Grant the loader insert/truncate and audit-update permissions.
- [x] Grant reader select through explicit and default privileges.
- [x] Verify 56 data tables plus the two audit tables before load.

### 2.2 Load order and recovery behavior

- [x] Load metadata first: `MRFILES`, `MRCOLS`, `MRDOC`, `MRSAB`, `MRRANK`.
- [x] Load concept anchor `MRCONSO`.
- [x] Load semantic types and definitions: `MRSTY`, `MRDEF`.
- [x] Load relationships and hierarchy: `MRREL`, `MRHIER`.
- [x] Load attributes: `MRSAT`.
- [x] Load maps/history: `MRMAP`, `MRSMAP`, `MRCUI`, `MRAUI`, `MRHIST`.
- [x] Load normalized string/word tables: `MRXNS_ENG`, `MRXNW_ENG`, all 31 `MRXW_*` files.
- [x] Load ambiguity and change files last.
- [x] Use one transaction per file with `TRUNCATE` and `COPY FREEZE` in the same transaction.
- [x] Use client-side CSV `\copy`, delimiter `|`, NULL `''`, and control-A quote/escape.
- [x] Record file status, start/end time, expected rows, loaded rows, and failure text.
- [x] Skip already-loaded files only when release identity, file SHA-256, and loaded row count agree.
- [x] Prove failure rollback and resume: a deliberately malformed `MRFILES` copy rolled back its `TRUNCATE` (56 rows before and after); resetting that audit row then produced 1 reload and 55 skips.
- [x] Confirm every trailer value satisfies `_rrf_trailer IS NULL` through a table check constraint.
- [x] Confirm all 56 files reach `loaded` state.

### 2.3 Finalize table shape

- [x] Refuse finalization unless all files are loaded and source hashes still match.
- [x] Drop all temporary `_rrf_trailer` columns without rewriting data.
- [x] Apply current-release `NOT NULL` constraints.
- [x] Confirm no trailer column remains.

**Phase 2 exit gate:** All 392,875,930 rows are loaded into the 56 intended tables with exact source hashes and no truncation, rejected records, or untracked retries.

---

## Phase 3 — Constraints, indexes, and statistics

### 3.1 Integrity constraints

- [x] Add and validate primary keys: `MRCONSO(AUI)`, `MRDEF(ATUI)`, `MRRANK(SAB,TTY)`, `MRREL(RUI)`, `MRSAB(VSAB)`, `MRSAT(ATUI)`, and `MRSTY(ATUI)`.
- [x] Add targeted keys/indexes for ambiguity and change tables.
- [x] Treat a uniqueness failure as a release/import failure, not as a reason to discard rows; all seven release keys built without duplicate removal.

### 3.2 Query indexes

- [x] Add identifier indexes for CUI, AUI, LUI, SUI, source codes, hierarchy parents, relationship endpoints, map sets, semantic types, and attributes.
- [x] Add composite source/code and source/term-type indexes matching likely terminology lookups.
- [x] Add `(wd,cui)` indexes to all 31 language word tables.
- [x] Use PostgreSQL hash indexes for exact `MRCONSO.STR` and `MRXNS_ENG.NSTR` lookup instead of unsafe full-value B-trees.
- [x] Do not add `pg_trgm`/full-text indexes until a measured query contract requires their storage cost.
- [x] Build normal, resumable post-load indexes with PostgreSQL parallel maintenance; do not use slower `CONCURRENTLY` on the offline initial build.
- [x] Limit maintenance concurrency so the shared host remains responsive; all 77 secondary indexes and seven data primary keys built successfully.

### 3.3 Statistics

- [x] Raise statistics targets for skewed `SAB`, `LAT`, `TTY`, `SUPPRESS`, `REL`, `RELA`, `ATN`, and hierarchy source columns.
- [x] Run `ANALYZE` after indexes; all 56 data tables have current statistics.
- [x] Record final heap, TOAST, and index sizes by table in `database-validation-20260722-195520.log` and the durable evidence README.

**Phase 3 exit gate:** All required uniqueness rules and query indexes exist, statistics are current, and representative lookups use the intended indexes.

---

## Phase 4 — Release validation and semantic smoke

### 4.1 Exact structural controls

- [x] Confirm 56 imported data tables.
- [x] Confirm every table count equals `MRFILES.RWS`.
- [x] Confirm total imported rows equal 392,875,930.
- [x] Confirm `MRCONSO=18,064,970`, `MRSAT=105,826,727`, `MRREL=66,241,184`, and `MRHIER=44,803,899`.
- [x] Confirm 3,530,466 distinct `MRCONSO.CUI` values.
- [x] Confirm `MRSAB=201`, 197 current source rows, and all source encodings equal UTF-8.
- [x] Confirm `MRRANK=947`, lexical ranks `0001` through `0947`, with unique `(SAB,TTY)`.
- [x] Confirm empty-file tables remain present with zero rows.
- [x] Confirm no extra `MRCXT` or `.x`-derived table exists.

### 4.2 No-truncation controls

- [x] Confirm `max(length(MRSAT.ATV))=35,985`.
- [x] Confirm `max(length(MRDEF.DEF))=10,992`.
- [x] Confirm `max(length(MRCONSO.STR))=2,864`.
- [x] Confirm `max(length(MRXNS_ENG.NSTR))=2,528`.
- [x] Compare database min/average/max lengths for all 359 columns to the declared `MRCOLS` controls; zero mismatches.

### 4.3 Distribution and semantic controls

- [x] Reconcile the known `MRCONSO.SUPPRESS` distribution: `N=15,551,711`, `O=2,154,677`, `Y=349,091`, `E=9,491`.
- [x] Reconcile preferred/synonym term status: `TS.P=9,555,099`, `TS.S=8,509,871`.
- [x] Reconcile all 31 `MRCONSO.LAT` distributions against the source audit.
- [x] Smoke `C0001175` across names, semantic type `T047`, definitions, and relationships.
- [x] Smoke exact code lookup for RxNorm, SNOMED CT, LOINC, ICD-10-CM, and CPT.
- [x] Smoke multilingual word lookup, including Arabic and Spanish.
- [x] Use `EXPLAIN (ANALYZE, BUFFERS)` to prove representative CUI, source/code, Spanish word, and exact-string lookups use indexes.
- [x] Run validation as `umls_reader` to prove the consumer role is sufficient and read-only.

**Phase 4 exit gate:** Structural, cardinality, maximum-length, distribution, semantic, access, and query-plan evidence all agree with the source release.

---

## Phase 5 — Backup, recovery, and handoff

- [x] Recheck root and `/mnt/md0` free space after table/index creation and restore cleanup: 700 GiB and 539 GiB respectively.
- [x] Confirm WAL archiver has no new failures and `pg_wal` is not retained abnormally; the sole recorded archive failure predates this import.
- [x] Confirm the cluster-wide physical backup/WAL policy includes `umls_2026aa`, the next base backup is scheduled for 03:00 local time, and destination headroom exceeds the 150 GiB floor.
- [x] Produce versioned PostgreSQL 17 custom-format/zstd backup `umls_2026aa-20260722-200200.dump`: 5,191,213,608 bytes, SHA-256 `faefcc926baa58fded04a2ecb7f27696ad845fac68b842b46f419d71765166ce`.
- [x] Verify the backup artifact independently: `pg_restore --list` succeeded with 337 TOC entries, followed by a complete restore.
- [x] Restore into isolated `umls_2026aa_restore_verify` and rerun the regenerated structural, semantic, access, and 359-column validation set; all passed.
- [x] Remove the scratch target only after retaining evidence, confirming its exact owner/encoding/locale/size, and confirming zero active sessions; post-drop database count is zero.
- [x] Record backup artifact, verification command, restore target, 1m35.46s dump duration, 23m20.35s restore duration, and validation result in `docs/evidence/umls/2026AA/README.md`.
- [x] Document the immutable release replacement procedure for a future `2026AB` database in `db/umls/README.md`.
- [x] Document the optional Zephyrus read-only second connection; do not add it until an application use case is approved.
- [x] Update this checklist with final 73,851,352,755-byte database size, durations, evidence paths, and the accepted two-byte `MRDOC.RRF` UTF-8 discrepancy.

**Phase 5 exit gate:** The database is query-ready, least-privilege, backed up, independently restorable, and handed off with reproducible release evidence.

---

## Completion evidence register

| Evidence | Required result | Status |
|---|---|---|
| Checked-in import tooling | Reproducible package; no licensed data/secrets | Passed — shell/Python syntax, regeneration, and repo-content audit |
| Source validation log | 56/56 files pass UTF-8/rows/fields/trailer/hash | Passed — `source-validation-20260722-195239.log` |
| Target release audit | Exact manifest and release digest | Passed — `06e31698…99a8` |
| Load audit | 56/56 loaded, 392,875,930 rows | Passed — 5m54.889724s |
| Constraint/index inventory | Required keys and query indexes present | Passed — seven data PKs, 77 secondary indexes, all valid |
| Validation transcript | Exact structural, length, distribution, semantic results | Passed — `database-validation-20260722-195520.log` |
| Role-boundary transcript | Loader/reader positive and `PUBLIC` negative checks | Passed — generated validation plus targeted privilege probes |
| Query-plan transcript | Representative lookups use intended indexes | Passed — `query-smoke-20260722-194517.log` |
| Backup verification | Backup independently verified | Passed — SHA-256, 337-entry TOC, complete logical restore |
| Scratch restore transcript | Restored target passes validation | Passed — protected `*.restore-validation.log`; scratch removed |

Durable non-licensed summary: [`docs/evidence/umls/2026AA/README.md`](../../evidence/umls/2026AA/README.md).
