# DEVLOG — iOS Build-Side Tranche (2026-07-27)

**Scope:** the staff-iOS build-side levers declined at the CI-duration program closeout and **reopened by [SU] the same day** ("Yes I want those minutes back. I want complete optimization."). Post-program work — the program itself remains closed (`DEVLOG-ci-duration-closeout-2026-07-26.md`).
**Shipped:** PR #99 (`c6c90a13`, the split + `ARCHS=arm64`) and PR #100 (`6f25d366`, XcodeGen cache removed on measurement). Branch protection updated in the same window: **14 required contexts** (added `Hummingbird patient (iOS release)`).

## Design basis (4-agent analysis before any edit)

- **The merge gate was a pair, not a single job:** `Hummingbird patient (iOS)` (~10.4 m nominal, required) alternated with the Frontend→Browser serial chain (~11.5–13 m finish offset) as the slowest required check. Staff iOS (~15.2–18.5 m, *not* required) set the full-run wall in 7/7 recent runs.
- **Diff-gating any iOS verify/fixture step is forbidden:** they are §3.2.7-mandated contract controls, and the verdict-reuse gatekeeper is step-blind — a PR-lane step skip would silently become the deploy evidence for the squash SHA. The sanctioned shape is *splitting whole steps into parallel sibling jobs*: every control still executes on every change set.
- **Q4's "keep Release in-job" ruling was premised on the scarce macos-26 pool** that S5 abandoned; macos-15 queues in 10–14 s, so a sibling VM is cheap.
- The Release simulator builds were **fat arm64+x86_64 binaries** whose artifacts are only plist/`strings`-verified, never executed on x86_64.

## What landed

| Change | Mechanism | Outcome |
|---|---|---|
| Patient job split | `Hummingbird patient (iOS)` (required) sheds XcodeGen install, project-drift verify, source boundary, Release build, and both artifact verifies into the new **required** sibling `Hummingbird patient (iOS release)`. The test job builds the committed xcodeproj directly | Test job = checkout, sim boot, Debug build, XCTest, XCUITest. Calm projection ~10.4 → **~6.5 m**; sibling measured **1m16s / 1m19s** across both samples |
| Staff job split | `Hummingbird staff (iOS)` keeps one load-bearing `xcodegen generate` (measured ≤8 s vs the 100–160 s verify step it replaces); determinism verify, DTO fixture decode, Release build, and transport verify move to sibling `Hummingbird staff (iOS release)` (not required, matching the test job) | Calm projection ~15.2–18.5 → **~13 m**, XCUITest-bound thereafter; sibling measured **2m44s / 2m01s** |
| `ARCHS=arm64` on both Release builds | The artifact is verified arch-agnostically and never executed on x86_64; the fat slice only doubled the compile | Staff sibling's whole chain (2× generate + diff + DTO decode + Release + transport verify) fits in ~2 m — the Release compile roughly halved |
| Required contexts 13 → 14 | `Hummingbird patient (iOS release)` added via the branch-protection API in the same change window as the #99 merge (strict preserved) | The Release boundary checks (`HBPPatientAPIEnabled=false`, empty base URL, no ATS exceptions, production hostname binding) keep gating merges |
| Hygiene | `COMPILER_INDEX_STORE_ENABLE=NO` + `-showBuildTimingSummary` on all four build steps | Defaults-drift insurance + per-step observability for any future pass |

## Measured null (recorded so nobody re-spends the effort)

- **XcodeGen binary cache (shipped in #99, removed in #100).** On #99's own run, both sibling jobs installed XcodeGen from the pinned release asset in **0–1 s** (same-datacenter download), while the staff test job's cache path cost **41 s restore + 40 s first-run `--version`** on a degraded VM. Step cost tracks VM health, not network; a cache cannot beat a ~1 s download and adds a cache-service dependency. The sha256-pinned plain download stands.

## Measurement context

Both post-split samples (runs 30284124065, 30285723272) landed in a degraded macos-15 window — a documented fleet-health pattern (same-day evidence: identical branches ran 2–7× slower at 14:21–15:40 UTC than at 03:11 UTC with identical 10–14 s queue delays). The split's savings are arithmetic, not statistical: the steps *removed* from each test job measured (on those very runs) ~100–160 s verify + ~26 s DTO + ~193–332 s Release on staff, and ~28–49 s XcodeGen + ~21–169 s verify + ~46–190 s Release on patient — deltas that hold at any VM speed multiplier. Calm-VM walls will accrue from routine PR runs.

## Standing conclusions

- **The nominal merge gate is now Browser-chain-bound (~11.5–13 m finish offset)**: Frontend (4m43s) → Browser (6m37s) run serially. That chain is the next gate lever (e.g., overlapping Playwright setup with the frontend build) — out of this tranche's scope, and the plan §5 note about Playwright `workers:1` (deliberate SSE-safety choice) still applies to any attempt.
- **Staff iOS is XCUITest-bound (~8–10 m of a ~13 m calm job)** — S3's measured null (no clone parallelization on standard runners) is the binding constraint; further staff-wall cuts require either journey-content decisions (governance, not CI edits) or larger runner pools.
- **DerivedData caching stays rejected**: the plan §5 do-NOT-do entry stands, and after the split the Debug build is no longer on the gate path's critical section; the exact-key compiled-intermediates variant documented in the tranche analysis is not worth its fragility at the current step costs.
- macOS fleet-health variance (2–7×) now dominates all remaining iOS wall variance; no CI edit fixes that class.
