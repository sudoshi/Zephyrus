# DEVLOG — Browser-Chain Decouple (2026-07-27)

**Scope:** the merge-gate's nominal tail after the iOS build-side tranche — the `Frontend → Browser/DAST` serial chain. Post-program work (the CI-duration program closed 2026-07-26; this and the iOS tranche are [SU]-directed follow-ons). Continues `DEVLOG-ios-build-side-tranche-2026-07-27.md`.
**Shipped:** PR #103 (`996a5336`). Branch protection updated in the same window: **15 required contexts** (added `Frontend build (Vite)`).

## The lever

S6 (PR #70) made Browser and DAST download the frontend job's vite artifact instead of building their own — one production build per run, ~2.2 m compute saved. Correct then: backend shards ran 7–25 m and nothing on this chain gated. But it coupled Browser's start to the *whole* frontend job (4m49s), and the vite build sits **behind** tsc (30s) + Vitest (2m03s) inside that job — 2m33s of test time Browser never consumes. Once the program brought everything else to ≤7.3 m, that serialization *was* the merge gate.

## What landed

| Change | Mechanism | Outcome |
|---|---|---|
| Thin build job | New `Frontend build (Vite)`: checkout, node+npm cache, `npm ci`, `vite build` (+ release evidence), upload `vite-production-build`. The vite build + artifact upload move out of the `Frontend (Vitest / Vite)` job | Job wall **2m12s** (projected 2m14s) |
| Browser/DAST repoint | `needs: [changes, frontend]` → `[changes, frontend-build]` (DAST moves too — the `needs` edge must follow the artifact *producer*, or it races the upload). `if:` expressions, skip-build env flags, download steps byte-identical | Browser finish **+12m15s → +9m24s** (~2m50s cut); DAST **+8m05s → +5m21s** |
| Frontend job unchanged name | Keeps tsc, Vitest (push-only `--coverage`), UI-canon, evidence — now runs fully parallel, off the gate path | Finish +3m27s, no longer a Browser dependency |
| Required contexts 14 → 15 | `Frontend build (Vite)` added via the branch-protection API, same window as merge (strict preserved) | **Non-negotiable guard** — see below |

## The guard: why the thin job must be required

Skipped check runs *satisfy* required status checks (repo-proven: the S4b docs-only fast path merges PRs whose required suite jobs are all job-level skipped). If the vite build moved into an *unrequired* thin job, a broken build would fail only that non-required check; Browser and DAST would then skip via `needs`-failure and be **satisfied-as-skipped**; the PR would merge broken. So the thin job had to join the required set. (Before this change, a vite failure red-flagged the required `Frontend (Vitest / Vite)` job directly — the restructure moved that coverage out, which is exactly why the new required context is mandatory, not optional.) Deploy-gate and verdict-reuse tooling are run-level only (verified: no job-name enumeration), so the new job just joins the all-jobs-green bar.

## Measured (PR run 30300441227, calm Linux lane)

```
Frontend build (Vite)            wall 2m12s   finish +2m31s
Frontend (Vitest / Vite)         wall 3m08s   finish +3m27s   (parallel, off gate)
Browser (Playwright / Chromium)  wall 6m51s   finish +9m24s   (was +12m15s median)
DAST (OWASP ZAP baseline)        wall 2m48s   finish +5m21s   (was +8m05s median)
```

Linux runners are near-deterministic (frontend-job wall σ≈4s over 5 prior runs), so unlike the iOS tranche this saving reads cleanly on a single run.

## Standing conclusions

- **The merge gate is now floor-bound.** With Browser at +9m24s, the slowest *required* context is whichever of Browser (~+9.5 m) and `Hummingbird patient (iOS)` (~+10–14 m, macOS-fleet-dependent) is unlucky. On a calm macOS lane the gate is ~+9.5 m; there is no single remaining structural lever that moves it without either a macOS-fleet change (out of our control) or larger runner pools (S3 territory).
- **Next Browser lever, if ever wanted, is inside the Playwright step** (4m44s–5m55s = migrate + seed + server boot + tests inside `scripts/run-browser-suite.sh`). Everything else in the job is ~90s of tight overhead. `workers:1` stays (SSE-safety do-NOT-do).
- **DAST was left on `frontend-build` too** rather than a bespoke arrangement — its +5m21s finish is comfortably inside the Browser tail, so no further split buys run-level time.

## Concurrent-session hazards cleared during this tranche (all by established remedies)

1. **Cross-branch gitleaks red** — a prose sentence in the unmerged Nightingale branch's devlog (an enumeration listing an API-path phrase next to a slash-separated word pair) tripped `generic-api-key` in the full-history scan, redding every PR's Security check. The Nightingale session had *independently* fixed the identical false-positive on main with a tighter anchored allowlist; my redundant fix collapsed to empty during rebase. (Do not reproduce the literal trigger phrase in prose — this devlog's first draft did, and red-flagged its own Security check; describe it, don't quote it.)
2. **Product-wide Hummingbird Patient → Nightingale rename (#101)** merged mid-flight — verified it left the CI job *display names* unchanged (required contexts unaffected) and that `NIGHTINGALE_CONTEXT_KEY` propagated consistently into the browser/dast job envs.
3. **Docker Hub image-pull flake** on `feature-3` (`gh run rerun`-class) and two `BEHIND` base moves — handled without touching the change.
