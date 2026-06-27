# 07 — Implementation Roadmap & Phasing

The plan is sequenced around one hard truth from the audit: **three platform gaps (token
auth, push, BFF) gate every feature**, so they are built first; then feature waves land
**live-data-first** (RTDC, Ops approvals, Transport, EVS) before **net-new-backend** features
(ED actions, PI). Estimates are ranges for a focused team; treat them as relative sizing, not
commitments.

---

## Phase 0 — Platform Foundation  *(the unblock; ~6–8 weeks)*

**Goal:** a signed-in worker on both platforms can authenticate with biometrics, hit a
secured BFF, receive a push, and see one real RTDC tile. Nothing user-facing ships, but
everything after depends on it.

**Backend**
- Sanctum token auth (`HasApiTokens`, `/api/auth/token{,/refresh,/revoke}`,
  `change-password`) — additive, honoring the locked flow. *(OIDC+PKCE path stubbed.)*
- `mobile_devices` registry + push service (APNs `.p8` + FCM v1) behind a `PushNotifier`.
- **BFF scaffold** (`/api/mobile/v1/*`) with uniform envelope, role-scoping, PHI-minimization.
- **Assignment model**: `user_id` FKs on owners + `user_unit` pivot (prereq for For-You &
  routing); RBAC policy layer; remediate the open (auth-less) operational endpoints.

**Mobile (both platforms)**
- Repo + CI scaffold; **KMP `shared`** (`domain`/`data`/`platform`) skeleton; SKIE bridge.
- **Design-token pipeline** (DTCG → Compose/SwiftUI) wired; `core-ui` primitives (`KpiTile`,
  `StatusChip`, `Panel`) on both platforms. *(Started — see [design-tokens/](../design-tokens/).)*
- Auth flow (token + **biometric unlock** + auto-lock + Keychain/Keystore), cert pinning.
- BFF client (Ktor), SQLDelight cache, outbox skeleton, push registration.

**Exit criteria:** auth regression suite green (locked flow unchanged); a device receives a
test push; one live `/rtdc/census` tile renders from the BFF on iOS + Android; security
checklist §1–§3 pass.

---

## Phase 1 — Frontline workers + bed flow  *(highest, fastest value; ~6–8 weeks)*

**Why first:** all **LIVE** endpoints, clean lifecycles, and the clearest mobile win
(transporter/EVS are *mobile users by nature*; bed managers desperately want placement on the
go).

- **Transport** (P1 persona): claim → run trip → structured handoff; full 17-status flow;
  STAT push.
- **EVS** (P2 persona): claim → clean → complete; isolation SOP; completion notifies bed mgr.
- **RTDC beds & barriers** (P3/P5 personas): live unit census + safe capacity (Reverb WS);
  pending bed requests + **transparent placement decision** (accept/edit/reject); log/resolve
  **barriers** (+ `BarrierUpdated` broadcast).
- **The "For You" queue v1** + **notification router v1** (tiers, routing, quiet hours,
  budget) across these domains.
- First **widget / Live Activity** (active trip; unit/house status glance).

**Exit:** a transporter claims a STAT job from the lock screen in < 10 s; a bed manager places
a bed from a push; SLA/capacity/barrier evaluators fire correctly within budget.

---

## Phase 2 — Decisions & the OR board  *(ops-leader + OR; ~8–10 weeks)*

- **Ops approvals/actions** (P6): approve/reject/assign/start/complete — **reuses `/api/ops/*`
  verbatim**; the marquee "approvals on the go." Agent-inbox glance tiles.
- **Command Center / Executive Brief** (P9): house strain index (0–4), hero KPIs with trust
  badges, the server-composed brief; sparse exec notifications.
- **Perioperative** (P4/P7): live room board + my cases + single-case tracking; advance
  status; **safety-note SLA** acknowledge; pre-op milestone ack; transport-to-OR. *(After the
  three OR backend fixes.)*
- **RTDC huddles** (P3/P6): unit-huddle steps (WS-synced) + **huddle action-items** (new API).
- Dynamic Island / Live Activity for room board + house strain; iOS **Critical Alerts** +
  Android full-screen-intent wired for **T1 only**.

**Exit:** a capacity lead approves an action from a notification; an OR nurse acknowledges an
overdue safety note; the exec home is a single quiet strain screen that escalates correctly.

---

## Phase 3 — Awareness breadth  *(~6–8 weeks)*

- **ED glanceable signals** (P9/P5): boarding, diversion, LWBS, surge — from Command-Center/
  Analytics (live); diversion endpoint + `DiversionUpdated` broadcast; ESI 1–2 LWBS as T1.
- **Staffing** (P10): open requests, below-minimum-safe alerts, create/source/assign/fill.
- **Regional/inter-facility transfer**; case metrics tiles.
- **Watch / Wear** complications for house/unit status; widget breadth.

**Exit:** ED breaches page the right leaders within budget; a staffing coordinator fills a gap
from mobile; glance surfaces cover house status across OS widgets/watch.

---

## Phase 4 — Net-new backend features  *(~8–10 weeks; backend-led)*

- **PI / PDSA** (P8): build the missing write paths, then PDSA ownership + stage advance +
  barrier ack on mobile.
- **ED actions**: boarder-dwell evaluator; and—if/when the net-new ED write path exists—live
  board / triage acknowledgements.
- **Integration health** alerts (connector-down, dead-letter spike) for ops/admin.
- Simulation **read** (results/promoted recommendations) — authoring stays web.

**Exit:** PI leads manage PDSA cycles on mobile; ED dwell breaches notify; ops sees feed
health.

---

## Phase 5 — Hardening, polish, GA  *(~4–6 weeks)*

- Full **security + clinical-safety reviews** (notification taxonomy sign-off); penetration
  test; PHI-leak audit; accessibility audit (WCAG 2.2 AA).
- Performance/battery tuning (WS lifecycle, background sync, payload sizes via BFF).
- Offline-edge hardening (conflict/409 UX, outbox reconciliation, stale-badge correctness).
- Store readiness (privacy nutrition labels, Critical Alerts entitlement approval, data-
  safety form), pilot → phased rollout behind feature flags.

**Exit:** all [security release gates](06-security-hipaa.md#9-release-gate-acceptance-criteria)
pass; pilot units validate time-to-act and notification budgets; GA.

---

## Timeline at a glance

```
P0 Foundation   ██████          auth · push · BFF · tokens · RBAC · assignment model
P1 Frontline      ██████        Transport · EVS · RTDC beds/barriers · For-You · notif v1
P2 Decisions        ████████    Ops approvals · Command/Brief · OR board · huddles
P3 Awareness            ██████  ED signals · Staffing · transfers · watch/widgets
P4 Net-new                ████████ PI/PDSA · ED actions · integration health
P5 Hardening/GA               ██████ security+safety review · perf · rollout
```
*Indicative; P1–P2 can overlap once the BFF pattern is set. Total ~9–12 months to GA for a
focused team, with usable internal pilots from end of P1.*

---

## Team shape (recommended)

| Role | Count | Focus |
|------|------:|-------|
| iOS (Swift/SwiftUI) | 2 | native UI, widgets/Live Activities, watch |
| Android (Kotlin/Compose) | 2 | native UI, Glance widgets, Wear |
| KMP/shared | 1 | domain/data/sync/tokens (pairs with both) |
| Backend (Laravel) | 1–2 | BFF, auth, push, evaluators, RBAC, broadcasts |
| Design | 1 | token parity, mobile component specs, glanceability |
| QA / security | 1 | conformance + security/PHI gates |
| Clinical-safety advisor | (part) | notification taxonomy + tier sign-off |
| PM/EM | 1 | sequencing, store, rollout |

---

## Top risks & mitigations

| Risk | Severity | Mitigation |
|------|----------|------------|
| **KMP commitment decided late** → expensive re-architecture | High | Decide **now** (§D2). Floor: separate apps + mandated OpenAPI contract + shared conformance spec. |
| **Earned-urgency mis-tuned** → alarm fatigue or missed breach (safety) | High | Treat the taxonomy as a clinical-safety system; configurable bands; clinical sign-off gate; track T1 precision + per-shift budget. |
| **PHI leak** (notifications/logs/snapshots/SDKs) | High | PHI-free push by design + CI payload linter + FLAG_SECURE/privacy view + BAA/PHI-free SDKs; PHI-leak audit gate. |
| **Auth additivity breaks the locked flow** | High | Strict additive design; auth regression suite as a P0 gate; never touch protected files. |
| **Net-new backend gates ED/PI** slips features | Medium | Sequence them last (P4); ship glanceable signals first; keep deep-link-to-web fallback. |
| **No background socket / push reliability** on battery-constrained devices | Medium | Push-first + delta-sync; never hold a bg socket; poll-on-resume fallback; test on real devices. |
| **Apple Critical Alerts entitlement / Android FSI permission delays** | Medium | Apply early (P2 lead time); degrade T1 to high-importance until granted. |
| **Web endpoints currently auth-less** become a security finding | Medium | Remediate in P0 regardless of mobile; BFF is the only mobile surface. |
| **Reference/OR backend bugs** block P2 | Low | Fix list in [04 §6](04-backend-requirements.md) scheduled before P2. |

---

## Definition of done (program level)

- The [feature-parity matrix](01-feature-parity-matrix.md) is fully reconciled: every
  GLANCE/ACT/NOTIFY row shipped or explicitly deferred with a tracked backend ticket; every
  WEB row has a working deep link.
- All [security release gates](06-security-hipaa.md#9-release-gate-acceptance-criteria) pass.
- The [notification taxonomy](05-notifications-earned-urgency.md) has clinical-safety sign-off
  and meets its precision/budget criteria in pilot.
- One design system, three platforms, verified non-divergent by the token CI check.
