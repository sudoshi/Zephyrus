# Cockpit — P8 "Mount-Anywhere" WS-5/6/7 — Devlog 2026-07-05

## Summary

Completed the final workstreams of **Zephyrus 2.0 P8 — the Mount-Anywhere Cockpit**
(`docs/ZEPHYRUS-2.0-PLAN.md`). WS-1→WS-4 (scope resolver, scoped faces, patient-lens A2P
web surface, live drill descent) had already shipped; this session landed **WS-6a** (the
scope-leakage RBAC gate), **WS-5** (mount presets + the multi-display wall + the scope
picker + the service-line persona), **WS-6b** (the app-chrome safety floor + admin threshold
editor), and **WS-7** (tests + broadcast gate + deploy).

Executed as an **agent swarm** (per the session goal): a read-only *understand* workflow
mapped the exact integration points; five parallel foreground agents built the disjoint
leaf components (each canon-constrained, no shared-file edits); the shared nexus wiring and
all commits were done in the main loop; a read-only **adversarial verification** workflow
double-checked every contribution against the spec acceptance criteria before deploy.

## Starting state
- P8 WS-1→WS-4 on `main` (`7503bf6`), not yet deployed. Backend already had the scope
  resolver (`CockpitScopeResolver`), scoped-face builder, and the `/scopes` / `/face` /
  `kpi-definitions` (GET+PUT) endpoints — but **no mount authorization** (any authenticated
  user could `?scope=unit:<any>` and read that unit's live census), no wall preset, no scope
  picker, no app-chrome stale banner, no SSE consumer, and no admin threshold-editor UI.
- A concurrent session was landing the Arena / OCEL "Part X" object-centric process-mining
  work on `main` in parallel (no file overlap with the cockpit; staged my files explicitly).

## Delivered

**WS-6a — the mount model (`c722ba6` → reverted by `406bf0a` after review + CMIO ruling).**
The first build added a per-unit mount gate (`CockpitScopeAuthorizer::canMount()`, 403 on an
out-of-assignment `unit:`/`service_line:` mount). The **adversarial verification pass caught
that it was inconsistent security theater**: a scoped face is per-unit bed OCCUPANCY (counts,
no patient identity) — the *same* house-wide data the `/snapshot` heat strip and `/drill/rtdc`
already serve to every authenticated user (plan §A0). Blocking `?scope=unit:SICU` while the
identical SICU occupancy stayed on the house RTDC board protected no confidentiality. **CMIO
ruling (2026-07-05): per-unit occupancy is house-wide; the real PHI boundary is A2P.** So the
gate was removed: `?scope=` is a relevance default (the resolver still lands you on your unit),
not a confidentiality wall; the A2P patient descent stays gated by `EnforceFlowLens`
(`PatientLensApiTest`), and the ED board's patient rows carry only opaque ptoks resolved at
that gated descent. `CockpitScopeMountTest` asserts the house-wide model.

**WS-5 — mount presets + wall + picker (`ce2f412`).**
- `ScopePicker` (native `<select>`, grouped House / My units / Units / Departments / Service
  lines) consuming the RBAC-filtered `/api/cockpit/scopes`; selection full-navs to `?scope=`.
- `DashboardLayout` wall preset: chrome-stripped (a minimal `WallClock` strip replaces the
  TopNavbar; the **RBAC session + the forced `ChangePasswordModal` are preserved** per
  `auth-system.md`), `fullBleed`, **dark-locked** (soft unadvertised `?theme=light` escape),
  `data-density=wall`.
- `WallClock` (1 Hz, `tabular-nums`) + `useIdleReset` **auto-timeout-to-glance**: after
  inactivity a wall mount closes any open drill/patient overlay and returns to its glance,
  **preserving `?scope`** (a unit wall returns to its unit face, not the house) — the
  CMIO-owned PHI mitigation for an always-on screen.
- Activated the reserved **service-line** RoleSwitcher persona (selectable, `?role=`-synced).
- **Tests:** `ScopePicker` + `useIdleReset`; RoleSwitcher/store specs updated for the now-live
  slot.

**WS-6b — the safety floor (`94fdfdc`).**
- `StaleDataBanner` hoisted **app-chrome-wide** in `CommandCenter` so it fires at *every*
  mount (house, scoped face, **and wall** — the scoped face had no banner before); the two
  inline copies in `CockpitOverview` + `CommandCenterView` were removed (the subtle aging dot
  + sr-only recovery announce stay).
- `useCockpitStream` — an `EventSource` consumer for `/api/cockpit/stream` with capped
  reconnect backoff: the **prod-safe live path** when `BROADCAST_CONNECTION=null` / Reverb is
  down. The 45 s poll + TanStack keep-last-good remain the fallback (echo stays isolated in
  `live.ts`).
- `data-density` multiplier (`app.css`); reduced-motion was already globally gated (verified);
  `tabular-nums` handles digit reflow at 80–125 % zoom.
- **Admin threshold editor** (`Pages/Admin/CockpitThresholds`) self-fetching GET/PUT
  `/api/cockpit/kpi-definitions` (both now `AdminMiddleware`-gated + audited) + route + nav —
  a CMIO tunes band edges without a deploy.
- **Tests:** `StaleDataBanner` + threshold-editor; `CockpitOverview` / `CommandCenterView` /
  snapshot-API specs updated for the moved banner + admin-gated GET.

**WS-7 — tests / broadcast gate / deploy.**
- `CockpitBroadcastTest`: `Event::fake` + `SnapshotBuilder::refresh()` asserts the PHI-free
  `{facility_key, generated_at}` reload ping dispatches on the public `hospital.cockpit`
  channel with alias `cockpit.updated`.

## Gate results (this session)
- **Backend cockpit suite: 96/96** (stable across repeated runs) incl. mount-model 4/4 +
  threshold editor 4/4 + broadcast 1/1. Mobile + Admin 50 passed / 1 skipped. `RouteSmoke` green.
- **Frontend: 270/270** vitest; `tsc --noEmit` clean; `vite build` exit 0; `check-ui-canon.sh`
  green (raw-palette ratchet unchanged ≤ 76).
- **Adversarial verification:** a 5-reviewer workflow tried to break each contribution. It
  found real defects — all now fixed (`f1bab21`, `406bf0a`): the WS-6a mount gate was
  inconsistent (→ removed per the CMIO ruling above); the wall dark-lock clobbered the user's
  persisted theme and the `?theme=light` escape was a no-op (→ transient snapshot/restore +
  authoritative); the scope picker dropped `?role=` on navigation (→ preserved); the
  `data-density` multiplier was inert against rem-based text (→ real root-level `.cockpit-wall`
  scale); the service-line PERSONA activation was inert (→ reverted; service line is a SCOPE
  via the picker); the live-ping invalidation churned the static catalogs (→ predicate-narrowed);
  the recovery announcement + stale banner didn't cover the scoped face (→ hoisted app-wide +
  driven off the face's own freshness); `StaleDataBanner` used polite `status` for a hard
  failure (→ `role="alert"`); `useCockpitStream` was untested (→ unit tests added).

## Known-unrelated failures
`tests/Feature/Ops/AgentControlPlaneTest` + `InterventionAttributionTest` fail
non-deterministically with `SQLSTATE[42P01] relation "prod.users" does not exist` /
`duplicate key … (migrations,…)` — a **shared-test-DB race** from the concurrent Arena session
running migrations/tests against the same test database (failure count varies run-to-run; my
commits touch zero migrations/seeders/Ops files). Not a P8 regression.

## Deploy runbook (prod)
1. `./deploy.sh --frontend` (build in dev + rsync) then `./deploy.sh` for the PHP caches — or
   the full `./deploy.sh`. deploy.sh **restarts Apache only** and **skips migrations** by
   design (there are no P8 migrations).
2. `sudo -A systemctl restart php8.5-fpm` — required so FPM workers pick up env; deploy.sh
   does not restart FPM.
3. **Broadcast cutover:** confirm prod `.env` has `BROADCAST_CONNECTION=reverb` (config
   default is `null` — the documented footgun) and `php artisan config:clear` ran (deploy.sh
   does). Verify a real broadcast: dispatch `CockpitSnapshotUpdated` (or let the scheduler
   refresh) and confirm the Reverb daemon relays it + a browser client refetches.
4. No `zephyrus:demo-seed` needed (no new seed data); the threshold editor reads the existing
   `ops.metric_definitions`.
