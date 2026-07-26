# CI Duration Optimization Plan — 2026-07-24

**Goal:** Cut nominal PR wall-clock from ~25–27 min to **~18–19 min this week** and **~13–14 min after the structural tier**, eliminate the unbudgeted **+35 min macOS queue tail** and the **~25 min merge→deploy duplication**, and make backend shard times *deterministic* — while honoring every §3.2.9 gate and the immutable-release evidence chain.

**Method:** All numbers below are measured from step-level timing of three real runs (`30112436573` pre-#60 main 73 min; `30126216355` post-#60 main 24.4 min; `30130213593` PR #61 27 min) plus the per-test JUnit artifacts PR #60 added, parsed class-by-class. No estimate in this plan is un-sourced. Investigation evidence: `/tmp/ci-analysis/` (3 run JSONs + 4 dimension findings + critique); durable copies of the shard timings live in the 30-day `backend-feature-*-release-evidence` artifacts.

**Prior art this plan extends (does not replace):** `docs/hummingbird/ZEPHYRUS-HUMMINGBIRD-FUNCTIONAL-PARITY-AND-PATIENT-EXPERIENCE-PLAN-2026-07-19.md` §3.2.9 — its gates, budgets, and ordering are binding. PR #60 executed the first gate (JUnit timing artifacts ✅, Cockpit payload amplification removal ✅: feature-7 4,265 s → 1,403 s, `LaboratoryCockpitMetricsTest` 2,701 s → 160 s).

---

## 1. The measured baseline — what actually takes so long

### 1.1 Wall-clock anatomy (all-green runs)

| | pre-#60 main | post-#60 main | PR #61 run |
|---|---:|---:|---:|
| Wall clock (nominal) | 73.0 m | 24.4 m | 26.9 m |
| Critical-path job | feature-7 (72.9 m) | feature-7 (24.2 m) | staff iOS (26.9 m) |
| Worst backend shard | 72.9 m | 24.2 m | 21.5 m |
| Staff iOS | 20.7 m | 22.8 m | 26.9 m |
| Total backend shard compute | 182.6 m | 98.4 m | 85.2 m |

### 1.2 Four facts that reframe the problem

1. **Staff iOS is NOT a required check.** Branch protection requires only **17 of the 19 contexts** — `Hummingbird staff (iOS)` and `Hummingbird staff (Android)` are not among them (verified via `gh api repos/sudoshi/Zephyrus/branches/main/protection`). The *merge-blocking* critical path is therefore the **worst backend shard (21.5–24.2 m)**, not iOS. iOS gates only the *deploy* path (deploy.sh requires the whole run green).
2. **The true wall clock is sometimes 62 min, not 27.** On PR run `30130213593`, 18 jobs started at +0.0 m but staff iOS **queued 35.4 min** for a `macos-26` runner before its 26.9 m of work. The scarce `macos-26` pool (needed for the iOS-26 SDK) is the largest single source of unbudgeted variance; Linux queue delay measured 0–3 s across all runs — Linux concurrency is not a constraint.
3. **87% of backend compute is repeated setup, not tests.** Of 5,104 s total suite time (PR run), **4,426 s** is consumed by 135 test cases across 26 classes whose `setUp()` re-runs 5–6 seeders + `AncillaryDemoScenarioService::refresh` (~33–44 s) before *every test method* (e.g. `tests/Feature/Cockpit/LaboratoryCockpitMetricsTest.php:47-55`). Those cases average 32.8 s each; the other 1,505 cases average **0.45 s**. Comparison: `RadiologyCockpitMetricsTest` — same seeders, no scenario dependence — runs 4 tests in 3.8 s.
4. **Every merge pays CI twice.** The PR run proves the tree, then the squash commit re-runs the *identical tree* on main (~25 m) before `deploy.sh`'s gate clears. Because branch protection has `strict: true` (branches must be up to date), the squash tree-sha equals the tested PR head tree-sha essentially always — the second run adds no information.

### 1.3 Where each critical job's minutes go (PR run `30130213593`)

**Staff iOS (26.9 m):** setup/verify ~0.9 m → Build Debug 2.30 m → Build Release 2.18 m → **boot simulator 5.02 m** (variance 1.8–5.0 m; runs strictly *after* both builds, `ci.yml:694-700`) → XCTest 4.13 m (which **recompiles from scratch** — the Debug build at `ci.yml:671` writes to a derivedDataPath the test steps never read, `ci.yml:709/724`) → **XCUITest 12.07 m** (18 tests, 4 classes, `-parallel-testing-enabled NO` at `ci.yml:712/727`; `PatientCommunicationRoutingUITests` holds 11 of the 18 tests, ≥20 app launches, 12 `.keepAlways` screenshots — today's 57 s snapshot-timeout flake lives here and cost a 20 m full-job rerun).

**Worst backend shard (21.5 m):** container init 21–33 s, composer (cached) 2–5 s, migrate 6–13 s **against a DB the tests never touch** (tests self-provision `zephyrus_test_<hex>` per process, `tests/bootstrap.php:12-14`), then PHPUnit 20.8 m. Shard membership is `sorted-filename index % 8` (`scripts/ci/run-backend-test-shard.sh:25-38`) — **adding 3 test files in PR #61 moved the heavy Ancillary/Lab classes from shard 7 to shard 0** (feature-0: 8.9 m → 21.5 m; feature-7: 24.2 m → 9.6 m). Load balance is a coin flip re-dealt by every test-file addition.

**Duplicated work across jobs (per run):** vite production build ×3 (frontend 100 s + Playwright 71 s + ZAP 61 s), `composer install` ×12 (only quality + shards have the cache), migrate ×12, E2E seeding ×2 (Playwright + ZAP each re-migrate + `E2eTestSeeder`, `CommandCenterDemoSeeder` alone 15 s), Playwright chromium re-downloaded cold every run (no cache), plus 19 × container/checkout/setup ≈ 4.6 m of container init alone. A **docs-only commit pays all of it** — `ci.yml:3-7` has no paths filtering (today's regenerated `.md` report ran the full 19-job suite).

**Velocity failure modes (not throughput):** (a) `npm audit` in the security gate red-flagged *main* for hours on a mid-day registry advisory unrelated to any diff; (b) `cancel-in-progress: true` applies to main pushes too (`ci.yml:9-11`) — two rapid merges cancel the first commit's run, leaving it **permanently unable to satisfy the deploy gate**; (c) a single flaky XCUITest costs a 20+ m full-job rerun because no in-job retry exists.

---

## 2. Binding constraints (violating any of these is a plan failure)

- **§3.2.9 ordering gate:** setup-vs-body + query-count instrumentation must land **before** the weighted shard manifest ("must profile those paths and test setup directly BEFORE introducing a weighted manifest or any index"). The manifest requires **rolling medians from clean exact-SHA runs**.
- **§3.2.9 prohibitions:** no SQLite swap, no trigger/migration suppression, no dirty-DB reuse, no shared mutable state between tests, budget changes need recorded evidence + review, a timeout *increase* is not optimization (a decrease is fine).
- **Required-checks contract:** 17 required contexts exist by *name*. Any job rename/merge/removal must update branch protection in the same change window or PRs become unmergeable (this kills the "delete the DAST job" variant — DAST **is** required).
- **Immutable-release evidence chain:** `deploy.sh` + `tests/Deployment/github-ci-gate.sh` require a green run on the exact release SHA. Any verdict-reuse/fast-path design must still produce a green, auditable run for that SHA (all-jobs-skipped runs conclude `success` and skipped required checks satisfy protection — verified — but the evidence-chain semantics need an explicit [SU] ruling; see item S4).
- **Budget-evidence hygiene:** runs used to ratify §3.2.9 budgets must have flake-retries disabled.

---

## 3. Tier 1 — Quick wins (this week; each independently shippable)

### Q1. Preboot the iOS simulator during the builds — **−3 to −5 m staff iOS**
The boot step (1.8–5.0 m) runs strictly after both builds but depends on nothing they produce.
- [x] Move `Select and boot an available iPhone Simulator` (staff `ci.yml:694-700`, patient `903-909`) to immediately after checkout; make `xcrun simctl boot` non-blocking (background boot), and add a cheap `xcrun simctl bootstatus $UDID -b` wait right before the first test step. Boot then overlaps the ~4–5 m build window and its cost collapses to ~0.
- Verify: staff iOS step timeline shows boot wait <30 s on 3 consecutive runs.

### Q2. `build-for-testing` + `test-without-building` — **net ~−4 m staff iOS**
The standalone Debug build's artifacts are never reused; XCTest recompiles everything.
- [x] Replace `Build Debug for iPhone Simulator` (`ci.yml:663-674`) with `xcodebuild build-for-testing` targeting `$RUNNER_TEMP/hummingbird-staff-ios-tests` (the derivedDataPath the test steps already use), and switch both test invocations (`ci.yml:709/724`) to `test-without-building`. Same change for patient iOS (`~884-939`).
- Verify: one compile phase total in the job log; XCTest step drops from 4.1 m to ≤1.5 m.

### Q3. In-job XCUITest flake retry (PR lanes only) — **−20 to −27 m per flake occurrence**
- [x] Add `-retry-tests-on-failure -maximum-test-repetitions 3` to the XCUITest invocations (`ci.yml:717-730`, `926-939`), gated on `github.event_name == 'pull_request'` so §3.2.9 budget-evidence runs (main) stay retry-free. *(shipped as `-retry-tests-on-failure -test-iterations 3 -test-repetition-relaunch-enabled YES` — `-maximum-test-repetitions` is the test-plan GUI name, not an xcodebuild flag; usage exit 64.)*
- Verify: inject a known-flaky rerun; job self-heals without a full rerun.

### Q4. Resequence Release build + transport verify after the test steps — **−2.2 m to first-failure**
- [x] Move `ci.yml:676-692` (Build Release + transport verify) after XCUITest so test failures surface ~2 m sooner; keep them in-job (the separate-job variant costs a scarce macOS slot — see S5).

### Q5. Security gate: decouple dependency audits from live registry events — **kills red-main incidents**
- [x] In `scripts/security/run-security-suite.sh:19-22`, run `composer audit`/`npm audit`/`pip-audit` as **hard gates only when the diff touches the corresponding lockfile** (`git diff --name-only origin/main...HEAD`), plus an unconditional scheduled nightly workflow that opens an issue on new advisories. Gitleaks/semgrep/edge-security stay hard-gated on every run.
- Rationale: today's `brace-expansion` advisory turned main red for hours and cost three CI round-trips on an unrelated one-line PR. Advisory response becomes a *scheduled, owned* activity instead of a merge-blocking ambush.

### Q6. §3.2.9 instrumentation prerequisite — **unblocks the whole structural tier**
- [x] Add setup-vs-test-body wall-time split + aggregate DB query-count/time to the shard harness for the residual heavy classes (the JUnit artifacts already give per-test totals; add a `setUp` timer via a base-class hook + `DB::listen` counter emitted into the release-evidence dir). This is the unchecked §3.2.9 box that *gates* the weighted manifest (S1) and the seeding rework (S2).

### Q7. Hygiene ratchets (all trivial, all off critical path)
- [x] `timeout-minutes: 90 → 30` on backend shards (`ci.yml:137`) — matches the ratified "no shard above 30 m" budget; caps hung-shard burn.
- [x] `cancel-in-progress: ${{ github.ref != 'refs/heads/main' }}` (`ci.yml:11`) — closes the permanently-undeployable-superseded-main-commit hole.
- [ ] Pint `--parallel` (`ci.yml:117`): quality job 101 s → ~35 s. **DEFERRED:** needs Pint ≥ 1.22; the 1.20→1.29.3 bump restyles ~150 files — dedicated bump+reformat PR.
- [x] Cache Playwright chromium (`~/.cache/ms-playwright`, keyed on the lockfile's playwright version) + add the existing composer-cache block to browser (`ci.yml:~432`) and dast (`~517`) jobs.
- [x] Drop `--coverage` from PR-lane Vitest (`ci.yml:265`; nothing consumes the lcov — keep it on main pushes so the coverage record persists).

**Tier-1 projected nominal wall:** staff iOS 26.9 m → **~16.5–18.6 m** (Q1+Q2+Q4 compose on the step chain; boot overlap bounded by build length). Backend untouched (gated by Q6→S1), so on an unlucky modulo deal the wall is the worst backend shard **~19–24 m**; median run **~18–19 m**. The macOS queue tail remains (addressed in S5).

---

## 4. Tier 2 — Structural (next sprint)

### S1. Stable weighted shard manifest (LPT) — **worst shard 21.5 m → ~11.6 m, deterministically**
*Gated behind Q6 per §3.2.9 ordering; weights from rolling medians of 3 clean exact-SHA runs.*
- [x] Replace the modulo deal (`run-backend-test-shard.sh:33-38`) with a committed `tests/ci/shard-manifest.json` (path → shard, LPT-packed from the JUnit medians). Unlisted files get a deterministic default weight + LPT slot at runtime. Add the §3.2.9-mandated verifier: every discovered test appears exactly once, no duplicates, no silent exclusions, manifest drift fails CI.
- *Shipped 2026-07-25: weights = per-file medians over 5 evidence runs; measured pack = floor file `AncillaryDemoScenarioTest` alone on shard 0 at 790.7 s, all other bins exactly 771.7 s. Verifier lives in the backend-quality job.*
- Arithmetic: LPT over measured class weights yields max bin ≈ **638 s** PHPUnit (~11.6 m job) vs 1,245 s observed; more shards buy ≤12 s because `AncillaryDemoScenarioTest` (625.8 s) is itself the floor — do not add shards.

### S2. Kill setup amplification in the ~21 consumer classes — **−38 m suite compute**
*The sanctioned §3.2.9 shape: minimal governed fixtures + at least one full-seeder integration case per protected invariant; no dirty-DB reuse, isolation preserved.*
- [x] Shipped as a **class-scoped committed scenario** rather than minimal fixtures — stronger than the
  original sketch because every test keeps the FULL governed scenario: `UsesCommittedAncillaryScenario`
  builds the 5 seeders + `refresh()` once per class, committed (RefreshDatabase disconnects after each
  per-test rollback, so an open outer transaction cannot span tests), before the per-test transaction
  begins; each test still runs in its own rollback transaction over a byte-identical baseline — this is
  NOT dirty-DB reuse, and isolation is preserved. Grounded in a 26-class + full-service audit
  (2026-07-25): builds self-clear via owner-scoped DELETEs + idempotent upserts, single connection,
  no commits/TRUNCATE/DDL, fully anchor-derived; all 22 pure consumers share one anchor. The 4
  generator-contract tests hoist seeders only — `refresh()` in their bodies IS the subject.
  `tests/TestCase.php` guard wipes all schemas (`IsolatedTestDatabase::resetAllSchemas`) + re-migrates
  when a non-scenario class follows in-process; `resolve-shard-files.py` orders scenario classes last
  per shard so CI never pays that path. Scenario builds: 135 → ~26 per full run.

### S3. Parallelize XCUITest (2 simulator clones) + split the 11-test routing class — **staff iOS UI step 12.1 m → ~6.5–7.5 m**
- [x] `project.yml:19-20` `parallelizable: true`, `-parallel-testing-enabled YES -parallel-testing-worker-count 2` (`ci.yml:727`), and split `PatientCommunicationRoutingUITests` (11 of 18 tests; XCTest distributes per-class, so without the split two workers cap at −4 m). *Attempted 2026-07-25 and CLOSED as a **measured null** (PR #77, never merged — see §5 do-NOT-do). Two live samples ran 17.6 m and 26.4 m vs the 12.1 m serial baseline: on 3-core `macos-15` runners the two clones contend (cold-iteration tests 2–3× slower, which induces timeout flakes), and under parallel testing `-retry-tests-on-failure` reruns the ENTIRE suite per flake, not the failed test. The 5/6 class split is preserved in closed PR #77 if XCUITest ever moves to larger runners.*

### S4. Tree-sha verdict reuse on the squash push + docs-only fast path — **merge→deploy ~25 m → ~1–2 m**
> **[SU] evidence-chain ruling recorded 2026-07-24** ("Proceed"): on a reused main run, the verdict
> evidence for the deployed SHA is the linked fully-green PR run for the byte-identical git tree; the
> gatekeeper's step summary records both SHAs, the shared tree SHA, and the reused run URL.
- [x] Verdict reuse (a): `changes` gatekeeper on main pushes resolves the squash subject's PR number,
  compares the squash commit's tree-sha to the PR head's tree-sha via the git-objects API (no checkout),
  and requires a **fully-successful** `ci.yml` run for that head — the same all-jobs-green bar the deploy
  gate applies to main runs today, so a PR run that passed only its 17 required contexts (e.g. a
  non-required staff-iOS flake) correctly fails open to a full main suite. All 11 job definitions gained
  `needs: changes` + `if: needs.changes.outputs.reuse != 'true'`; reuse can never trigger on
  `pull_request` events, so branch protection semantics are untouched. Empirically verified on real
  history: PR #61 and #63 squash trees are byte-identical to their PR-head trees.
- [x] Docs-only fast path (b): the gatekeeper classifies the change set (PR file list, or the push
  compare API on main with a 300-file truncation guard) as docs-only when every file is under `docs/`
  or a root-level `*.md`, with `docs/hummingbird/**` excluded (generated docs are contract-verified by
  backend-quality). The 10 suite jobs skip on `docs_only`; the Security job (gitleaks / SAST) runs on
  every change set regardless. All classification failures fail open to the full suite.

### S5. De-risk the `macos-26` queue tail — **removes the +35 m variance**
- [ ] In order: (a) test whether `macos-15`'s Xcode selection now covers the needed iOS-26 SDK — if yes, leave the scarce pool entirely; (b) evaluate larger-runner pools; (c) failing both, move XCUITest journeys to main-push/nightly lanes only (permissible — staff iOS is not a required PR check — but record the coverage trade-off explicitly).
- [ ] Either way: emit queue-delay (job `started_at` − run `created_at`) into release evidence so the tail is *measured* continuously.

### S6. Single vite build per run — **browser 9.0 → ~7.5 m, DAST 4.3 → ~2.5 m**
- [x] Frontend job uploads `public/build`; browser + DAST download it and set the already-existing `PLAYWRIGHT_SKIP_BUILD` / `DAST_SKIP_BUILD` flags (`run-browser-suite.sh:74`, `run-dast-suite.sh:75`). Optionally fold ZAP into the browser job against the still-running server later — but DAST is a *required check by name*, so any fold must ship with a branch-protection update in the same window.

---

## 5. Tier 3 — Deep (§3.2.9 query/test remediation; the floor-setters)

- [x] **D1. Profile `AncillaryDemoScenarioService::refresh` + `SnapshotBuilder::buildWithContext` + Lab services** with `EXPLAIN (ANALYZE, BUFFERS, WAL)` on the 66-order/328-milestone fixture in a disposable DB (§3.2.9 verbatim). Every shard layout is lower-bounded by the 625.8 s `AncillaryDemoScenarioTest`; a 2× refresh cut takes backend to ~6.5–7 m and is what ratifies the stage-2 ≤15 m budget. *Profiled 2026-07-25 (docs/audits/D1-ancillary-refresh-profile-2026-07-25.md): root cause = stale planner stats on freshly provisioned test DBs (nested-loop misplans, 15.2 s of the 25.6 s roll-forward); targeted post-refresh ANALYZE shipped — AncillaryDemoScenarioTest 201→123 s locally (1.64×). Remaining candidates (reconciliation-key index, batched status updates, provenance index) recorded for D2/D3.* *Follow-ups shipped 2026-07-25 (PR #78, deployed to prod): reconciliation lookup index rebuilt with a provable `IS NOT NULL` partial predicate (the 2026_07_11 predicate was unprovable from the `->>` clause — dead index left in place, drop is approval-gated), `provenance_records(canonical_event_id)` FK index, and `markProjected()` batching the 672 per-event projection flips into chunked whereIn updates. AncillaryDemoScenarioTest 92.9→82.0 s local on top of D1. Note: #78 is NOT plan-§D2 below — the audit's "for D2/D3" label collided with the plan's item names.*
- [ ] **D2. Request-scoped laboratory aggregate snapshot** (one governed calculation shared by Cockpit provider, health service, drill) — §3.2.9's named remediation.
- [x] **D3. Paratest spike** (per-process isolation already exists: DB name keys on pid, `IsolatedTestDatabase.php:27`; array cache/session; pid-scoped disk). Only after D1 — it cannot break the single-class floor. Success enables 8 → 4 shard consolidation. *Spiked 2026-07-26 in an isolated clone — **FEASIBLE**, consolidation PR deferred until §D2 lands + fresh manifest evidence. Findings: paratest 7.8 installs under PHP 8.5 (Pest can't); pid-keyed DB provisioning composes untouched; naive one-pass runs violate S2's scenario-last ordering (59× duplicate-table from guard re-entry) and exhaust `max_locks_per_transaction` on a shared cluster (45× at p8). Three constraints make it green: (1) two-pass per shard — non-scenario files then scenario files (1,534 tests clean at p4 in 1m08s; scenario pass 135 tests with only pressure artifacts); (2) CI postgres service needs `-c max_locks_per_transaction=256`; (3) the three scenario anchor groups (07-11 / LabTat 07-12 / PharmacyTat 07-13) must not share one worker process — a growth assertion counted exactly 3× when all three anchors landed in one worker (1008 = 3×336). Also: Q6 NDJSON evidence needs per-worker filenames (two workers appending one file interleave) + a widened manifest-generator glob.*
- [x] **D4. Restore the two never-running tests**: `Api/ProcessAnalysisTest` + `Auth/AuthenticationFlowTest` are Pest-syntax, excluded by the sharder (`run-backend-test-shard.sh:27-28`) because Pest is uninstallable under PHP 8.5 — they run **nowhere** today. Convert to PHPUnit class syntax. Zero time saving; closes a silent coverage hole. *Shipped 2026-07-25 (PR #79): the hole was FOUR files — phpunit.xml also excluded `Unit/Services/ProcessAnalysisServiceTest` + `Unit/Services/DashboardServiceTest`. All four converted, every exclusion surface removed (phpunit.xml, both shard-script finds, manifest-generator EXCLUDED set); new feature files LPT-slot at runtime, no manifest regen needed. The dormant tests hid real contract drift, adapted after verification: change-password posts `new_password/*`, `/dashboard/improvement` 302s into the cockpit quality drill (P4a), `getPdsaCycle` returns the plan/study Show shape (no `phases` key). 63 tests / 298 assertions.*

**Do-NOT-do list (measured nulls — recorded so nobody re-spends the effort):** SPM/DerivedData caching (no SPM packages declared), per-job migrate-template DBs (in-process `migrate:fresh` ≈ 6 s and §3.2.9-sensitive), composer cache work in shards (installs already 2–5 s), Linux runner-pool changes (queue delay 0–3 s), adding backend shards past 8 (floor-bound), action version bumps (all current majors), Playwright sharding (off critical path; config `workers:1` is a deliberate SSE-safety choice — revisit only at stage-2), **XCUITest 2-clone parallelization on standard `macos-15` runners** (S3, measured 2026-07-25: 17.6 m and 26.4 m vs 12.1 m serial — 3-core clone contention induces the very flakes that trigger whole-suite retries; `-retry-tests-on-failure` retries the entire run under parallel testing, and retry-free main would red on most runs; revisit only on larger runner pools, split preserved in closed PR #77).

---

## 6. Projected outcomes

| Milestone | Nominal PR wall | Merge→deploy latency | Tail risk |
|---|---:|---:|---|
| Today | 24.3–26.9 m | ~25 m (second full run) | +35 m macOS queue; +20 m per iOS flake; red main on registry events |
| After Tier 1 | **~18–19 m median** | ~25 m | flake tail closed (Q3); red-main closed (Q5); queue tail remains |
| After Tier 2 | **~13–14 m** | **~1–2 m** (S4, post-ruling) | queue tail closed (S5); backend deterministic (S1) |
| After Tier 3 (D1 ×2 refresh cut) | **~12.5–13 m** (iOS-owned) | ~1–2 m | — |

Budgets (extend §3.2.9's): after S1 lands, ratchet backend `timeout-minutes` 30 → 20; after stage-2 ratification, adopt the ≤15 m backend critical-path budget with the mandated 3-clean-run evidence. Re-run the full 19-job suite with zero skipped tests before/after each tier and diff coverage counts, assertions, and invariants (§3.2.9 requirement) — a faster shard must not conceal a semantic regression.

## 7. Sequencing

Week 1: Q1–Q7 (all independent; Q6 first since S1/S2 wait on its data accumulating).
Sprint: S1 (after 3 clean runs of Q6 medians) → S2 (largest compute win) ∥ S3 ∥ S5 ∥ S6; S4 after the [SU] evidence-chain ruling.
Then: D1 → D2 → (D3 if D1 succeeds) ∥ D4 anytime.

*Meta-lesson from today, encoded above: the three CI round-trips (~75 min) this very investigation ran alongside were caused by (a) a registry advisory ambush → Q5, (b) npm-version skew between beastmode and runners → recorded in memory (`feedback_npm10_lockfile_ops`), and (c) a 57 s XCUITest snapshot timeout → Q3. The plan fixes the class of each.*
