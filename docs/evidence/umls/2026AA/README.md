# UMLS 2026AA PostgreSQL Import Evidence

| Field | Evidence |
| --- | --- |
| Date | 2026-07-22 |
| Source | Licensed local `2026AA/META` Rich Release Format directory |
| Target | PostgreSQL 17.10 database `umls_2026aa`, schema `umls` |
| Release | `2026AA`, Base Release for Spring 2026, dated 2026-05-04 |
| Manifest | `06e31698b61a0e347d8dc9b2d5b7ccbff44ec898eabe3e3d5c229ba2d96c99a8` |
| Result | PASS — source, import, indexes, access, semantics, backup, and isolated restore validated |

This directory contains summaries only. UMLS rows, extracts, generated SQL,
runtime logs, dumps, and credentials remain outside Git because the source is
licensed.

## Source and representation

- All 56 RRF files passed UTF-8, row-count, field-count, trailer, control-A,
  file-size, and SHA-256 checks.
- The release declares 392,875,930 rows and 32,889,841,451 characters. Physical
  RRF size is 32,889,841,453 bytes; the two-byte difference is the additional
  UTF-8 bytes for one en dash in `MRDOC.RRF`.
- Seven MetamorphoSys `.x` byte-offset indexes were excluded. `MRCXT` was not
  created because it is absent from `MRFILES.RRF`.
- Six language RRFs omitted by the legacy loaders were imported:
  `MRXW_ARA`, `MRXW_ISL`, `MRXW_LIT`, `MRXW_SLK`, `MRXW_SLV`, and `MRXW_UKR`.
- Release maxima disproved the legacy 4,000-character DDL:
  `MRSAT.ATV=35,985` and `MRDEF.DEF=10,992`; generated long-value columns use
  PostgreSQL `text`.
- Exhaustive length validation found that `MRRANK.RANK` is lexical, not
  numeric: its source values are zero-padded `0947` through `0001`. The
  generator now maps it to `varchar(4)`. Reconstructing `MRRANK.RRF` from the
  live database produced the source SHA-256
  `fad189192f347adb53389bbb58d267fdf5294dda7a91395ae0cb4f0b362c744e`.

## Import and database result

| Control | Result |
| --- | --- |
| Load | 56 files; 392,875,930 expected and loaded rows |
| Load elapsed | 5m54.889724s, from 19:20:39 to 19:26:34 EDT |
| Recovery drill | Failed `MRFILES` copy rolled back its `TRUNCATE`; targeted rerun loaded one file and skipped 55 |
| Tables | 56 data tables plus two audit tables |
| Keys/indexes | Seven data primary keys and 77 valid secondary indexes |
| Statistics | 56/56 data tables analyzed |
| Database size | 73,851,352,755 bytes (69 GiB) |
| Access | `PUBLIC` denied; `umls_reader` select-only; `umls_loader` limited to load/audit operations |

The largest final relations are `MRSAT` at 20 GiB, `MRREL` at 14 GiB,
`MRHIER` at 13 GiB, `MRCONSO` at 6.34 GiB, `MRXNW_ENG` at 5.10 GiB, and
`MRXW_ENG` at 4.57 GiB.

## Validation result

- All 56 database counts equal `MRFILES.RWS`; the total is exactly
  392,875,930.
- `MRCONSO=18,064,970`, `MRSAT=105,826,727`, `MRREL=66,241,184`, and
  `MRHIER=44,803,899`; `MRCONSO` contains 3,530,466 distinct CUIs.
- All 359 null-aware minimum, average, and maximum column-length profiles match
  `MRCOLS.RRF` with zero mismatches.
- Release distributions for `SUPPRESS`, `TS`, and all 31 languages reconcile.
- Concept `C0001175` resolves across names, semantic type `T047`, definitions,
  and relationships.
- Exact RxNorm, SNOMED CT, LOINC, ICD-10-CM, CPT, Arabic-word, and Spanish-word
  lookup smokes passed as `umls_reader`.
- Representative query plans used the intended CUI, source/code, Spanish word,
  and exact-string hash indexes.

Ignored local evidence logs are:

- `source-validation-20260722-195239.log`
- `index-20260722-193232.log`
- `query-smoke-20260722-194517.log`
- `database-validation-20260722-195520.log`

## Backup and isolated restore

The versioned custom-format backup is stored outside Git at
`/mnt/md0/postgres-backups/logical/umls_2026aa/umls_2026aa-20260722-200200.dump`.

| Control | Result |
| --- | --- |
| Backup format | PostgreSQL 17 custom format, zstd level 3 |
| Artifact size | 5,191,213,608 bytes (4.9 GiB) |
| Backup duration | 1m35.46s |
| Artifact SHA-256 | `faefcc926baa58fded04a2ecb7f27696ad845fac68b842b46f419d71765166ce` |
| TOC verification | `pg_restore --list` succeeded with 337 entries |
| Scratch target | `umls_2026aa_restore_verify`, UTF-8/C.utf8, 69 GiB |
| Restore duration | 23m20.35s with normal durability |
| Restore validation | Exact manifest regenerated; 56 files and 392,875,930 rows validated; 359 column profiles had zero mismatches |
| Cleanup | Scratch database rechecked with zero active sessions, dropped, and absence confirmed |

The cluster-wide physical backup and WAL policy also covers `umls_2026aa`; the
next scheduled base backup is at 03:00 local time. At final handoff, root had
700 GiB free and `/mnt/md0` had 539 GiB free. WAL archiving had no new failure;
its sole recorded failure predated the import.

## Operational handoff

- The reproducible import kit is [`db/umls/README.md`](../../../../db/umls/README.md).
- The evidence-gated implementation checklist is
  [`2026-07-22-umls-2026aa-postgresql-import-TODO.md`](../../../superpowers/plans/2026-07-22-umls-2026aa-postgresql-import-TODO.md).
- No Laravel connection, migration, route, API, deployment, or application
  credential was added. A future consumer requires a separately approved
  read-only connection and bounded query contract.
- A future UMLS release must use a new release-named database, pass the same
  gates and restore test, then be switched atomically; it must not overwrite
  this release in place.
