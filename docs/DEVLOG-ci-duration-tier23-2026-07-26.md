# DEVLOG — CI Duration Optimization, Tiers 2–3 (2026-07-25 → 2026-07-26)

**Scope:** Tiers 2–3 (S1–S6, D1–D4) of `docs/superpowers/plans/2026-07-24-ci-duration-optimization-plan.md`, plus the infrastructure bugs the work surfaced. Continues `DEVLOG-ci-duration-tier1-2026-07-24.md`.
**Shipped:** 16 merged PRs (#66–#83, #85) across two concurrent sessions over ~36 hours, coordinated through a shared claims file.
**Headline:** PR merge-gating wall ~25–27 m → **~10 m**; merge→deployable ~25 m → **~10 s**; docs PRs ~19 m → **~2 m**; backend shards 7–25 m coin-flip → **3.9–6.1 m deterministic**.

## What landed

| Item | PR (squash) | Change | Measured outcome |
|---|---|---|---|
| S4 verdict reuse | #66 (`646f9779`) | `changes` gatekeeper on main pushes: squash-subject → PR number → tree-sha equality via the git-objects API → requires a fully-successful ci.yml run for the byte-identical PR head; all jobs skip on reuse | Merge→deployable **~25 m → ~10 s**; reuse never fires on PR events, so branch protection is untouched. Ran 9-for-9 on real merges before the first fail-open |
| S4b docs fast path | #68 (`f7359046`) | Gatekeeper classifies change sets; docs-only (all `docs/` or root `*.md`, `docs/hummingbird/**` excluded) skips the 10 suite jobs; Security always runs | Docs PRs **~19 m → ~2 m** |
| S1 weighted shards | #69 (`fba6a303`) | LPT manifest from per-file medians over 5 evidence runs replaces the filename-modulo deal; runtime resolver LPT-slots unlisted files, hard-fails stale entries; §3.2.9 verifier proves union == discovery every run | Feature shards **7–25 m coin-flip → 8.5–15.5 m**; evidence gate honored (3 exact-SHA reruns, cross-attempt drift median 13.8% proved medians necessary) |
| S6 single vite build | #70 (`1e62b782`) | Frontend uploads `public/build`; browser + DAST download it (`PLAYWRIGHT_SKIP_BUILD`/`DAST_SKIP_BUILD`) | Browser **9.1 → 6.8 m**, DAST **4.2 → 3.0 m**; ~2.2 m compute saved per run |
| S5 macOS queue tail | #71 (`391c949b`) | Staff iOS macos-26 → macos-15 + pinned `DEVELOPER_DIR` Xcode 26.3 (macos-15 ships 26.0–26.3 side-by-side; the old "cannot parse iOS-26 SDK" comment described only the image default) | Queue delay **up to 35.4 m → 4 s** for +3.7 m work time |
| S2 committed scenario | #72 (`b0e9fa11`) | Class-scoped COMMITTED demo baseline (`UsesCommittedAncillaryScenario`): 5 seeders + `refresh()` once per class before the per-test transaction; 26 classes converted; §3.2.9 parity proven by NDJSON test-name diff (1,876 = 1,876, 0 missing/new) | Feature shards **8.5–15.5 → 3.9–9.1 m**; suite wall 16.3 m |
| D1 profile + fix | #73 (`126c1374`) | §3.2.9-verbatim profile (`EXPLAIN (ANALYZE, BUFFERS, WAL)`, disposable DB): root cause = stale planner statistics on freshly provisioned test DBs → `rows=1` nested-loop misplans; fix = failure-tolerant post-transaction `ANALYZE` of the 6 churned tables in `refresh()` | Roll-forward **25.6 → 11.2 s (2.3×)**; `AncillaryDemoScenarioTest` 201 → 123 s local. Audit: `docs/audits/D1-ancillary-refresh-profile-2026-07-25.md` |
| Manifest regen 1 | #74 (`20efdc62`) | Post-S2 weights from 3 samples | Shards 1–7 packed to exactly 245.5 s; floor 494.6 s |
| Timeout ratchet | #76 (`882b5c0c`) | Backend shard `timeout-minutes` 30 → 20, after the feature-1 residual was explained (below) | Hung-shard burn cap halved at ~2× headroom |
| D1 follow-ups | #78 (`9e725864`) | Audit findings 2/3/4: reconciliation lookup index rebuilt with a provable `IS NOT NULL` partial predicate (the 2026_07_11 predicate was unprovable from the `->>` clause — dead index left in place, drop is approval-gated); `provenance_records(canonical_event_id)` FK index; `markProjected()` batches 672 per-event flips into chunked `whereIn` updates | `AncillaryDemoScenarioTest` 92.9 → 82.0 s local; deployed to prod (batch-51 migrations) — the 6-hourly demo refresh benefits directly |
| D4 dead tests | #79 (`4bacc984`) | The plan's two never-running Pest files were actually **four** (phpunit.xml also excluded two Unit-tier); all converted to PHPUnit, every exclusion surface removed | 63 tests / 298 assertions restored. The dormant tests hid real contract drift (change-password field names, P4a drill redirect, `getPdsaCycle` shape) — fixed after verification against deployed behavior |
| Matrix-context fix | #81 (`25c3d7ef`) | S4b's job-level skip never expands the backend matrix → the nine required `Backend tests (…)` contexts stay "expected" forever → **docs-only PRs were unmergeable** (admin bypass is disabled). Fix: docs-only skip moved to step level; stub shards report success in ~1 m | First hit on #80; #80 and #82 then merged unattended. Any REQUIRED matrix context must never be skipped at job level on PR lanes |
| Manifest regen 2 | #83 (`446107ec`) | Post-#78 weights from 3 fresh samples; first regen carrying the D4-restored files (291 files, 0 unmeasured) | Floor **494.6 → 303.0 s**; `RadiologyDemoGeneratorTest` packs **alone** on shard 1 (its 176↔450 s swing no longer drags 47 files); live shards **3.9–6.1 m** |
| D3 paratest spike | #85 (`2798b6e5`, findings) | Spiked in an isolated clone: paratest 7.8 runs under PHP 8.5 (Pest can't); pid-keyed `IsolatedTestDatabase` composes untouched; 1,534 non-scenario tests clean at 4 workers in 1m08s | **Feasible.** Three constraints before the 8→4 VM consolidation: two-pass per shard (S2's scenario-last invariant), `-c max_locks_per_transaction=256` on the CI postgres service, anchor-group process isolation (one worker holding all three anchors tripled a no-growth count: 1008 = 3×336). Plus per-worker NDJSON evidence filenames |

## Measured nulls (recorded so nobody re-spends the effort)

- **S3, XCUITest 2-clone parallelization (PR #77, closed unmerged).** Mechanics worked — classes split 5/6 via a shared base, 9/9 distribution, warm-pass wall 5.6 m — but both live samples were *worse* than the 12.1 m serial baseline (17.6 m, 26.4 m). Two compounding causes: 3-core `macos-15` clone contention runs cold-iteration tests 2–3× slower (110.9 s vs 35.9 s warm, same test), which itself induces the timeout flakes; and under `-parallel-testing-enabled YES`, `-retry-tests-on-failure` reruns the **entire suite**, not the failed test, so every flake costs a full ~6–9 m iteration. On retry-free main this flake rate would red most runs. The class split is preserved in the closed PR for larger runner pools.

## Notable findings during execution

1. **Verdict reuse changes evidence economics.** A reused main run produces no fresh Q6 NDJSON artifacts — medians only accrue from full runs. `gh run rerun` of a green run re-executes the same tree and is the reliable evidence source; download artifacts *before* rerunning (a rerun replaces them).
2. **Gitleaks full-history scans red *all* PRs on any key-looking fixture in *any* commit, ever** (two occurrences: an unmerged codex branch's `replayKey` UUID; a merged Android test fixture). Fixes: scoped AND-allowlist in `.gitleaks.toml`, commit-pinned fingerprints in `.gitleaksignore`. Prefer non-entropy fixture values.
3. **Strict branch protection + a merge train = update-branch churn.** Every mid-flight merge forces `gh api -X PUT …/pulls/N/update-branch` (this gh lacks the subcommand) plus a full re-verify on every open PR. Sequencing merges beats parallel-opening PRs.
4. **The patient-Android emulator lane flaked twice in one day** across unrelated PRs (plus one iOS artifact-upload infra failure); `gh run rerun --failed` healed all three.
5. **S2's mechanisms are a reusable catalog** for anyone attempting committed fixtures over RefreshDatabase: an open outer transaction cannot span tests; `migrate:fresh` only drops search_path tables in a many-schema app; DELETE+INSERT churn decays both planner stats and visibility-map bits (EXPLAIN plan assertions flip with autovacuum timing); and test *ordering* becomes a correctness invariant that the sharder must encode.

## Where the program stands (2026-07-26)

| Metric | Program start (07-24) | Now |
|---|---|---|
| PR merge-gating wall (required checks) | ~25–27 m | **~10 m** |
| Full-suite wall (verdict-reuse eligibility) | ~27 m | ~16–21 m (staff iOS-bound) |
| Merge → deployable | ~25 m | **~10 s** (fully-green PR run) |
| Docs-only PR | ~19 m | **~2 m** |
| Backend feature shards | 7–25 m, filename-modulo | **3.9–6.1 m**, evidence-packed, drift hard-fails |
| Registry-advisory red-main class | live | killed (diff-gated audits + nightly sweep) |
| macOS queue tail | up to +35 m | 4 s |

## Remaining (all sequenced, none urgent)

- **plan-§D2** (request-scoped laboratory aggregate) — PR #84 open (concurrent session). After it lands: manifest regen, then optionally the D3 consolidation per the constraints above.
- **Query re-profile tranche** — `RadiologyDemoGeneratorTest`'s mega-test (36,893 queries, the last erratic weight) + general per-row ORM chattiness (~11k statements per scenario replace).
- **Pint 1.29 bump+reformat PR** — deferred until §D2 lands (the ~150-file restyle would collide).
- **Staff iOS build-side** (4.8 m Debug build, 2.7 m project verify, 2.1 m Release build) — the only lever that still moves the full-run wall; out of plan scope by design after S3's null, pending an explicit call.
