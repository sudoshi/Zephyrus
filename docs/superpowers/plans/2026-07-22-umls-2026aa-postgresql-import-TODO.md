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
- [ ] Keep UMLS access limited to explicit owner, loader, and reader roles and verify that `PUBLIC` has no database/schema access.

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
- [ ] Generate SHA-256 for every RRF and a deterministic release-manifest digest.
- [ ] Persist source controls in the target database before loading data.
- [ ] Re-run the source validator from the checked-in tooling and retain its evidence log.

---

## Phase 0 — Import package and operating contract

### 0.1 Version-controlled tooling

- [ ] Add `db/umls/README.md` with supported commands, recovery behavior, type policy, security boundary, and licensing warning.
- [ ] Add an ignored generated-artifact area for manifests, SQL, state, and logs.
- [ ] Add a manifest/DDL generator driven by the release's `MRFILES.RRF` and `MRCOLS.RRF`.
- [ ] Add a full source validator for UTF-8, row count, field count, RRF trailer, control-A absence, file size, and SHA-256.
- [ ] Add idempotent PostgreSQL role/database/schema bootstrap tooling.
- [ ] Add a resumable client-side `\copy` loader pinned to PostgreSQL 17.
- [ ] Add post-load finalization, constraints, indexes, statistics, and validation SQL generation.
- [ ] Add explicit commands for `prepare`, `source-validate`, `bootstrap`, `schema`, `load`, `finalize`, `index`, `validate`, `status`, and `all`.
- [ ] Make every command fail closed with `ON_ERROR_STOP`, nonzero exit status, and a visible file/table context.
- [ ] Ensure no password, connection URL, licensed row, or local secret is written to Git.

### 0.2 PostgreSQL type contract

- [ ] Map UMLS `integer` to PostgreSQL `bigint`; prove `MRFILES.BTS` cannot overflow.
- [ ] Map `numeric(5,2)` without loss.
- [ ] Map Oracle-style `char(n)` to `varchar(n)` to avoid PostgreSQL blank-padding behavior.
- [ ] Map fields declared at 1,000 characters or longer to `text`.
- [ ] Preserve `CVF` as character data rather than coercing it to integer.
- [ ] Create a checked-null `_rrf_trailer` column for the final RRF delimiter during load.
- [ ] Apply `NOT NULL` only after the release has loaded and current-release non-null evidence is known.
- [ ] Add primary/unique constraints only after bulk loading.
- [ ] Avoid broad foreign keys across polymorphic and historical UMLS identifiers.

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
- [ ] Recheck free space, archiver health, active backups, and competing long PostgreSQL work immediately before the first bulk load.
- [ ] Reserve at least 200 GiB on `/` and 150 GiB on `/mnt/md0` through completion of the first verified physical backup.

### 1.2 Roles, database, and schema

- [ ] Create non-login role `umls_owner`.
- [ ] Create non-login role `umls_loader` and grant it only the required load/audit privileges.
- [ ] Create non-login role `umls_reader` and grant it read-only privileges.
- [ ] Grant the local operator membership needed to assume loader/reader roles without embedding passwords.
- [ ] Create database `umls_2026aa` from `template0`, UTF-8, `C.utf8`, owned by `umls_owner`.
- [ ] Revoke default database access from `PUBLIC`.
- [ ] Create schema `umls`, owned by `umls_owner`.
- [ ] Revoke schema access and creation from `PUBLIC`.
- [ ] Configure default privileges so future UMLS tables remain readable only by `umls_reader` and writable only by the loader/owner boundary.
- [ ] Create release and file-level audit tables before any RRF table.
- [ ] Prove role boundaries with positive loader/reader tests and negative `PUBLIC` tests.

**Phase 1 exit gate:** The empty database exists with the intended locale and least-privilege roles, and its audit tables contain the exact source manifest.

---

## Phase 2 — Table creation and data load

### 2.1 Create the 56-table release schema

- [ ] Generate all 56 data tables from `MRFILES`/`MRCOLS`.
- [ ] Create all six language tables omitted by the legacy scripts.
- [ ] Exclude `MRCXT` and all seven `.x` files.
- [ ] Confirm the generated column order exactly matches each `MRFILES.FMT` declaration.
- [ ] Confirm long fields resolve to `text`, identifiers to non-padding strings, and numeric metadata to non-overflowing types.
- [ ] Grant the loader insert/truncate and audit-update permissions.
- [ ] Grant reader select through explicit and default privileges.
- [ ] Verify 56 data tables plus the two audit tables before load.

### 2.2 Load order and recovery behavior

- [ ] Load metadata first: `MRFILES`, `MRCOLS`, `MRDOC`, `MRSAB`, `MRRANK`.
- [ ] Load concept anchor `MRCONSO`.
- [ ] Load semantic types and definitions: `MRSTY`, `MRDEF`.
- [ ] Load relationships and hierarchy: `MRREL`, `MRHIER`.
- [ ] Load attributes: `MRSAT`.
- [ ] Load maps/history: `MRMAP`, `MRSMAP`, `MRCUI`, `MRAUI`, `MRHIST`.
- [ ] Load normalized string/word tables: `MRXNS_ENG`, `MRXNW_ENG`, all 31 `MRXW_*` files.
- [ ] Load ambiguity and change files last.
- [ ] Use one transaction per file with `TRUNCATE` and `COPY FREEZE` in the same transaction.
- [ ] Use client-side CSV `\copy`, delimiter `|`, NULL `''`, and control-A quote/escape.
- [ ] Record file status, start/end time, expected rows, loaded rows, and failure text.
- [ ] Skip already-loaded files only when release identity, file SHA-256, and loaded row count agree.
- [ ] Roll back a failed file completely and prove `--resume` restarts at that file.
- [ ] Confirm every trailer value satisfies `_rrf_trailer IS NULL` through a table check constraint.
- [ ] Confirm all 56 files reach `loaded` state.

### 2.3 Finalize table shape

- [ ] Refuse finalization unless all files are loaded and source hashes still match.
- [ ] Drop all temporary `_rrf_trailer` columns without rewriting data.
- [ ] Apply current-release `NOT NULL` constraints.
- [ ] Confirm no trailer column remains.

**Phase 2 exit gate:** All 392,875,930 rows are loaded into the 56 intended tables with exact source hashes and no truncation, rejected records, or untracked retries.

---

## Phase 3 — Constraints, indexes, and statistics

### 3.1 Integrity constraints

- [ ] Add and validate primary keys: `MRCONSO(AUI)`, `MRDEF(ATUI)`, `MRRANK(SAB,TTY)`, `MRREL(RUI)`, `MRSAB(VSAB)`, `MRSAT(ATUI)`, and `MRSTY(ATUI)`.
- [ ] Add targeted keys/indexes for ambiguity and change tables.
- [ ] Treat a uniqueness failure as a release/import failure, not as a reason to discard rows.

### 3.2 Query indexes

- [ ] Add identifier indexes for CUI, AUI, LUI, SUI, source codes, hierarchy parents, relationship endpoints, map sets, semantic types, and attributes.
- [ ] Add composite source/code and source/term-type indexes matching likely terminology lookups.
- [ ] Add `(wd,cui)` indexes to all 31 language word tables.
- [ ] Use PostgreSQL hash indexes for exact `MRCONSO.STR` and `MRXNS_ENG.NSTR` lookup instead of unsafe full-value B-trees.
- [ ] Do not add `pg_trgm`/full-text indexes until a measured query contract requires their storage cost.
- [ ] Build normal, resumable post-load indexes with PostgreSQL parallel maintenance; do not use slower `CONCURRENTLY` on the offline initial build.
- [ ] Limit maintenance concurrency so the shared host remains responsive.

### 3.3 Statistics

- [ ] Raise statistics targets for skewed `SAB`, `LAT`, `TTY`, `SUPPRESS`, `REL`, `RELA`, `ATN`, and hierarchy source columns.
- [ ] Run `ANALYZE` after indexes.
- [ ] Record final heap, TOAST, and index sizes by table.

**Phase 3 exit gate:** All required uniqueness rules and query indexes exist, statistics are current, and representative lookups use the intended indexes.

---

## Phase 4 — Release validation and semantic smoke

### 4.1 Exact structural controls

- [ ] Confirm 56 imported data tables.
- [ ] Confirm every table count equals `MRFILES.RWS`.
- [ ] Confirm total imported rows equal 392,875,930.
- [ ] Confirm `MRCONSO=18,064,970`, `MRSAT=105,826,727`, `MRREL=66,241,184`, and `MRHIER=44,803,899`.
- [ ] Confirm 3,530,466 distinct `MRCONSO.CUI` values.
- [ ] Confirm `MRSAB=201`, 197 current source rows, and all source encodings equal UTF-8.
- [ ] Confirm `MRRANK=947`, ranks 1 through 947, with unique `(SAB,TTY)`.
- [ ] Confirm empty-file tables remain present with zero rows.
- [ ] Confirm no extra `MRCXT` or `.x`-derived table exists.

### 4.2 No-truncation controls

- [ ] Confirm `max(length(MRSAT.ATV))=35,985`.
- [ ] Confirm `max(length(MRDEF.DEF))=10,992`.
- [ ] Confirm `max(length(MRCONSO.STR))=2,864`.
- [ ] Confirm `max(length(MRXNS_ENG.NSTR))=2,528`.
- [ ] Compare database min/average/max lengths to the declared `MRCOLS` controls.

### 4.3 Distribution and semantic controls

- [ ] Reconcile the known `MRCONSO.SUPPRESS` distribution: `N=15,551,711`, `O=2,154,677`, `Y=349,091`, `E=9,491`.
- [ ] Reconcile preferred/synonym term status: `TS.P=9,555,099`, `TS.S=8,509,871`.
- [ ] Reconcile all 31 `MRCONSO.LAT` distributions against the source audit.
- [ ] Smoke `C0001175` across names, semantic type `T047`, definitions, and relationships.
- [ ] Smoke exact code lookup for RxNorm, SNOMED CT, LOINC, ICD-10-CM, and CPT.
- [ ] Smoke multilingual word lookup, including Arabic and Spanish.
- [ ] Use `EXPLAIN (ANALYZE, BUFFERS)` to prove representative lookups use indexes.
- [ ] Run validation as `umls_reader` to prove the consumer role is sufficient and read-only.

**Phase 4 exit gate:** Structural, cardinality, maximum-length, distribution, semantic, access, and query-plan evidence all agree with the source release.

---

## Phase 5 — Backup, recovery, and handoff

- [ ] Recheck root and `/mnt/md0` free space after table/index creation.
- [ ] Confirm WAL archiver has no new failures and `pg_wal` is not retained abnormally.
- [ ] Confirm the next physical backup policy includes `umls_2026aa` and has sufficient destination capacity.
- [ ] Produce a versioned custom-format logical backup for database-level recovery testing, or document and approve a physical-only recovery boundary before skipping it.
- [ ] Verify the backup artifact independently (`pg_restore --list` for logical and/or `pg_verifybackup` for physical).
- [ ] Restore into an isolated scratch target and rerun the structural/semantic validation set.
- [ ] Remove the scratch target only after restore evidence is retained and its exact identity is rechecked.
- [ ] Record backup artifact, verification command, restore target, duration, and validation result.
- [ ] Document the release replacement procedure for a future `2026AB` database.
- [ ] Document the optional Zephyrus read-only second connection; do not add it until an application use case is approved.
- [ ] Update this checklist with final database size, durations, evidence paths, and any accepted discrepancy.

**Phase 5 exit gate:** The database is query-ready, least-privilege, backed up, independently restorable, and handed off with reproducible release evidence.

---

## Completion evidence register

| Evidence | Required result | Status |
|---|---|---|
| Checked-in import tooling | Reproducible package; no licensed data/secrets | Open |
| Source validation log | 56/56 files pass UTF-8/rows/fields/trailer/hash | Open |
| Target release audit | Exact manifest and release digest | Open |
| Load audit | 56/56 loaded, 392,875,930 rows | Open |
| Constraint/index inventory | Required keys and query indexes present | Open |
| Validation transcript | Exact structural, length, distribution, semantic results | Open |
| Role-boundary transcript | Loader/reader positive and `PUBLIC` negative checks | Open |
| Query-plan transcript | Representative lookups use intended indexes | Open |
| Backup verification | Backup independently verified | Open |
| Scratch restore transcript | Restored target passes validation | Open |

