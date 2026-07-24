# DEVLOG — UX/HFE Audit Remediation (P0 + Phase 1)

**Date:** 2026-07-17
**Branch / PR:** `feature/ux-hfe-remediation` → PR #37 (merged `e04d796`)
**Docs:** [audit](../audits/ZEPHYRUS_COMPREHENSIVE_UX_UI_HFE_AUDIT_2026-07-17.md) · [evaluation + phased plan](../plans/UX-HFE-REMEDIATION-PLAN-2026-07-17.md)

## What happened

An external comprehensive UX/UI/HFE audit of the production surface landed. Every claim was verified against the codebase, the production host, and the production database before being acted on. Two headline P1 findings were **misdiagnosed** by the audit:

1. **Patient Flow 4D model "missing"** — the `.glb` existed in repo, git, and on prod disk. The real cause: ModSecurity rule 1000003 (`/etc/apache2/zephyrus/edge-security.conf`) blocks the entire `/vendor/*` URL namespace as a sensitive filesystem path. Assets relocated to `public/facility-models/`; the security rule stays untouched. **Prod hotfixed live the same evening** (assets + `ZEPHYRUS_500_MODEL_URL`/`TILESET_URL` env keys + `config:cache`) — verified 200. Standing rule: public assets must never live under `public/vendor/`.
2. **Action Inbox vs Agent Inbox "contradiction" (8 vs 0)** — one store, one service, one endpoint, one query key. The page rendered loading/failed fetches as `0` (`?? 0`). Unknown-as-zero, fixed with explicit loading/error/retry states.

## P0 commit (`12fc176`)

- Turnover Times crash → shared `resolveSiteData()` (hard-coded `'MARH OR'` fallback removed), null guards, honest empty states
- Eddy stream → 45s inactivity timeout, interrupted-stream errors, composer can never wedge, failed draft preserved for retry
- Alert TTL re-raise → `COCKPIT_ALERT_TTL_HOURS` (72 default, 0 off): alerts open past TTL close to history and re-derive fresh; demo refresh resets alert lifecycle on world rebase; ticker ages humanized (`formatCoarseDurationSeconds`)
- A11y → Approve/Reject/Run/Dismiss carry proposal subjects; Service Huddle filters out of the heading; duplicate "Barriers" controls disambiguated
- Page identity → per-section H1s for `/analytics/*`; Process Analysis H1; Active Cycles ↔ nav label; Resources/Demand nav labels qualified by domain + horizon
- Virtual Rounds cancelled runs archived behind a toggle; Eddy evidence humanized (no raw JSON in composer or inspector); navbar wordmark + Eddy launcher yield below `sm`

## Phase 1 commit (`dbd2226`)

- **Alert acknowledgement**: `acknowledged_at/by/by_name` on `prod.cockpit_alerts` (migration `2026_07_17_100000`), `POST /api/cockpit/alerts/{id}/acknowledge` (idempotent, first-owner-wins), ticker ack affordance with optimistic rollback; **warn→crit escalation clears the ack** so worsening re-alarms
- **Approval review step**: Approve/Reject only inside the expanded evidence sheet (scope, rationale, confidence, expected impact, source, owner, requested/due/expires); `serializeRecommendationBrief` enriched additively
- **Drill freshness**: DrillModal header states demo-measure count + oldest-measure lag vs snapshot `asOf`
- **Scope banner**: scoped-mount face header sticky under the topbar

## Deliberately NOT done (audit rulings)

Stale synthetic Transport/Staffing (= intended demo env), login restructure (protected auth system), "Arena" rename (Part X concept), §7 top-level IA restructure (CMIO decision). See the plan doc §2.

## Deploy notes

- Phase 1 includes a migration → deploy with `DEPLOY_RUN_MIGRATIONS=1 ./deploy.sh`
- Merged to main alongside PR #36 (Home Hospital Phase 0) — the combined state was re-verified locally (tsc, 531 Vitest, cockpit suites) and by main CI before deploy
- The prod `.env` model-URL hotfix keys are now redundant with the repo config defaults (harmless)
- `/var/www/Zephyrus/.git` is a stale `master` checkout — never diagnose prod from it
