# DEVLOG — CI Duration Optimization, Program Closeout (2026-07-26)

**Scope:** the final tranche after the Tier 2–3 devlog snapshot (`DEVLOG-ci-duration-tier23-2026-07-26.md`, PR #86): plan-§D2 (#84), the Radiology/chattiness tranche (#87), the Pint 1.29 bump (#89), the post-#87 manifest regen (#90), and the D3 8→4 shard consolidation (#91).
**Status: program complete.** Every item in `docs/superpowers/plans/2026-07-24-ci-duration-optimization-plan.md` is now shipped, measured-null, or spiked-and-consumed. This devlog supersedes the "Remaining" section of the Tier 2–3 devlog.

## What landed

| Item | PR (squash) | Change | Measured outcome |
|---|---|---|---|
| plan-§D2 lab aggregate | #84 (`ccbdd27e`) | `LabAggregateSnapshotFactory` — the repo's first container `scoped()` binding — memoizes the {flow-board cockpitHealth, decision readinessSnapshot} pair eagerly together (the verified-empty reconciliation needs one consistent read); `LabCockpitHealthService` consumes it; `SnapshotBuilder::refresh()` flushes it so the authoritative snapshot never publishes a memo. Scope is deliberately the cockpit chain ONLY: memoizing `AncillaryReadinessService` is unsafe — the demo generators read it mid-refresh while the cohort is rewritten (stack-trace-proven) | Lab consumer classes 38.5→35.9 s local; 635-test sweep green; deployed to prod |
| Radiology/chattiness | #87 (`2dc37a7e`) | `CanonicalEventWriter` opt-in `markProjectedOnWrite` (rows born `projected`; `markProjected()` deleted — see finding 1); partial index on `ancillary_milestones(provenance_record_id)` (overturns #78's "companion not warranted" — see finding 2) | `RadiologyDemoGeneratorTest` 91.6→61.3 s (−33%); the feature-0 floor dissolved into the pack (6.1→4.5 m); deployed to prod (batch-52 migration, index verified) |
| Pint 1.29 bump | #89 (`3c362cee`) | `laravel/pint: ^1.29` + the full ~150-file restyle, as its own PR per the Tier-1 deferral | Quality-job Pint gate 40 s → ~0.5 s with `--parallel` |
| Manifest regen 3 | #90 (`42f8faa6`) | Post-#87 weights from 3 fresh samples | All 8 bins packed to exactly 237.5 s — the single-class floor is gone from the packing itself |
| D3 consolidation | #91 (`84b5f830`) | 4 feature shards × 2 paratest workers (9 → 5 backend jobs). The spike's ordering constraints are encoded in the shard runner: per-shard ordered passes (non-scenario → shared-anchor scenario → each anchor-override class in its own paratest invocation), which covers both the S2 scenario-last invariant and anchor-group process isolation. `make-paratest-suite.py` generates per-pass phpunit configs; `TimingEvidence` NDJSON is per-pid; 4-bin manifest at exactly 475.0 s each; branch protection trimmed 17 → 13 required contexts (feature-4..7 removed, strict preserved) | Live on its own PR run (30201161345): unit 1m17s, shards 4m06s/7m20s/6m15s/6m15s, all green; §3.2.9 parity 1,672 = 1,672 by NDJSON name-diff; local proof all 4 shards green 1m20s–1m51s |

Docs along the way: #85 (spike findings), #86 (Tier 2–3 devlog), #88 (plan §5 D2 tick).

## Notable findings during execution

1. **A post-hoc UPDATE of rows inserted in the same transaction defeats PostgreSQL's unchanged-key RI shortcut.** #78's batched `markProjected()` looked cheap but cost 1,956 ms per 336-row chunk — 914 ms payload-FK re-check + 912 ms `canonical_event_payload_object_guard`, both per-row, because the flip re-fires RI on rows the transaction itself created. The fix is to write the final value in the first place (`markProjectedOnWrite`), not to batch the rewrite.
2. **An `ON DELETE SET NULL` FK needs an index on the *referencing* side, and delete-side EXPLAIN cannot tell you.** #78 measured the delete plan and judged the `ancillary_milestones` companion index "not warranted"; #87's trigger-level profile showed 476 of 481 ms per provenance delete inside the RI trigger's referencing-table scans. The cost lives in the trigger, invisible to the statement's own plan.
3. **Every path in a phpunit XML config resolves relative to the config file** — generated temp-dir configs must absolutize bootstrap/file/source paths.
4. **Pint 1.29's fixer chain is not single-pass idempotent.** One file needed a second pass; local `--test --parallel` missed what CI caught. After a bulk reformat, rerun `--test` serial.
5. **A memoized read is a data change wherever a generator consumes the service mid-refresh.** The §D2 factory initially memoized an empty mid-transaction cohort into the committed-scenario build via `DischargePrioritiesService` → `AncillaryReadinessService`. Generators read live boards; the factory's scope boundary (cockpit chain only) is load-bearing and applies to any future Radiology/Pharmacy analogue.
6. **Memory-file work claims are TOCTOU-unsafe between concurrent sessions.** Two same-hour claim races (manifest regen, Pint bump) — the second produced a byte-identical duplicate branch that rebase auto-skipped. For same-hour work, check `gh pr list`/origin branches before starting, not just the claims file. Corollary: with strict branch protection, have the CI poller merge immediately on green — three mid-flight base moves each cost an update-branch plus a full ~25 m re-verify.

## Closeout verification (2026-07-27, 4-agent adversarial sweep)

Independent verification of the shipped state against `origin/main`, live CI, and prod before closing the program:

- **Implementation** — 6 of 7 recorded D3 claims verified against `origin/main` (matrix = 5 jobs; ordered passes with `LabTat` 07-12 / `PharmacyTat` 07-13 anchor solos; per-pid NDJSON + matching generator glob; absolutized per-pass configs; 4-bin manifest with `verify-shard-manifest.sh` proving union == discovery; branch protection strict with exactly 13 contexts, no feature-4..7). **One record correction:** the spike's `-c max_locks_per_transaction=256` CI-postgres constraint was never added to ci.yml — and doesn't need to be at the shipped shape. The spike observed lock exhaustion at 8/4 workers on a *shared* cluster; the shipped 2 workers run against a dedicated per-job service container and are green across three full runs. The constraint is real but conditional: it activates only if `BACKEND_SHARD_WORKERS` is raised. Recorded in the plan's D3 annotation.
- **Live CI** — consolidated walls hold beyond #91's own run: PR #94's full run (post-#91 code) shows unit 1m09s, shards 4m24s/7m17s/6m16s/6m44s. Every main push since `84b5f830` is green; verdict reuse concluded the four most recent main runs in 7–14 s each.
- **Prod** — all three program indexes verified in `pg_indexes` (`prod.ancillary_orders_reconciliation_lookup_idx`, `integration.provenance_canonical_event_idx`, `prod.ancillary_milestones_provenance_record_idx`); migration batches 51 and 52 confirmed in `prod.migrations`. PR #91 itself is CI/test-only (paratest sits in `require-dev`; no runtime prod files touched) — **no prod deploy owed for D3**.

## Final program metrics (2026-07-24 → 2026-07-26)

| Metric | Program start | Program close |
|---|---|---|
| PR merge-gating wall (required checks) | ~25–27 m | **~10 m** (staff-iOS-bound; backend off the critical path) |
| Merge → deployable | ~25 m | **~10 s** (verdict reuse on a fully-green PR run; 10-for-10 live) |
| Docs-only PR | ~19 m | **~2–3 m** |
| Backend test jobs per run | 9 VMs, feature shards 7–25 m coin-flip | **5 VMs, shards ~4–7 m**, evidence-packed, drift hard-fails |
| Single-class floor (`AncillaryDemoScenarioTest`) | 625.8 s, alone lower-bounding every layout | dissolved (64.6 s solo local after D1/#78/#87) |
| Registry-advisory red-main class | live | killed (diff-gated audits + nightly sweep) |
| macOS queue tail | up to +35 m | 4 s |
| Silent coverage holes | 4 never-running test files | 0 (63 tests / 298 assertions restored) |

## Still open (deliberately, none in program scope)

*All three items below were dispositioned by [SU] ruling on 2026-07-27 ("Close out those remaining items. Approved."):*

- **Staff iOS build-side** (4.8 m Debug build, 2.7 m project verify, 2.1 m Release build) — the only lever that still moves the full-run wall; pending an explicit call. *Closed without action: the optional tranche is declined; reopen only on explicit request.*
- **Dead index drop** (`ancillary_orders_reconciliation_key_idx`, superseded by #78's provable predicate) — non-additive, approval-gated. *Approved and executed: migration `2026_07_27_000100` drops it (`down()` restores the original definition); prod `pg_stat_user_indexes` at drop time showed 0 scans on it vs 2,303 on the successor. `AncillarySpineMigrationTest` now asserts the successor present and the dead index absent.*
- **Stage-2 §3.2.9 budget ratification** — *ratified: the ≤15 m backend critical-path budget is adopted with 3-clean-run evidence (runs 30201161345 / 30230900947 / 30233798984, worst backend shard 7m20s; backend lanes are retry-free on every lane). Recorded in the plan's Budgets annotation.*
- **S3 XCUITest parallelization** — revisit only on larger runner pools (split preserved in closed PR #77). *(Standing do-NOT-do, not an open item — unchanged.)*
