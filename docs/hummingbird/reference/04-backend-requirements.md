# 04 — Backend Requirements & Mobile API Contract

Hummingbird consumes Zephyrus. The audit found the backend is **not mobile-ready in three
specific ways** — and these gate every feature. This document specifies the **additive**
Laravel work required, ordered so the platform foundation (P0) unblocks the feature waves.

> **Hard constraint:** all backend work here is **additive**. The authentication system is
> locked by [.claude/rules/auth-system.md](../../../.claude/rules/auth-system.md) — the
> temp-password / `must_change_password` / Resend flow, the Register/Login/ChangePassword
> behavior, and the superuser account **must not be modified or bypassed**. Mobile auth is a
> *new parallel path*, never a change to the existing one.

---

## 1. The three gating gaps (P0 — nothing ships without these)

### Gap 1 — No mobile-consumable authentication
**Today:** session-cookie auth only (`web` guard). **Sanctum is installed (`^4.0`) but
dormant** — configured SPA-only, `User` lacks `HasApiTokens`, **zero `auth:sanctum` routes**.
OIDC/Authentik exists (full Auth-Code + PKCE) but also lands a *web session*, not a token.

**Required (additive):**
1. Add `HasApiTokens` to `User`; enable Sanctum **personal access tokens** (or first-party
   OAuth2 via Passport if the org prefers full OAuth — see decision below).
2. New endpoints (a *new* path, not a change to the locked flow):
   - `POST /api/auth/token` — exchange username + password (or OIDC code) for an access
     token + refresh token. **Must honor `must_change_password`**: if true, return a
     `password_change_required` challenge and a scoped token good only for the change call.
   - `POST /api/auth/token/refresh` — rotate refresh → new short-lived access.
   - `POST /api/auth/token/revoke` — logout / remote-wipe support.
   - `POST /api/auth/change-password` — token-scoped forced change (mirrors web behavior,
     does not replace it).
3. Wrap all mobile API routes in an `auth:sanctum` group (the BFF, below).
4. **Decision:** **Sanctum PATs** for v1 (simplest, first-party, already installed), with an
   **OIDC+PKCE** path reusing the existing Authentik integration for SSO orgs. Passport only
   if a third-party OAuth client ecosystem is needed later.

### Gap 2 — No push infrastructure
**Today:** zero APNs/FCM, no device-token registry, no notification service. Only Reverb WS
(foreground) + one demo SSE.

**Required (net-new):**
1. **Device registry:** `mobile_devices` (user_id, platform, push_token, app_version,
   locale, last_seen, revoked_at) + `POST /api/mobile/v1/devices` / `DELETE …/{id}`.
2. **Push service:** an APNs (HTTP/2, token-based `.p8`) + FCM v1 sender, fronted by a
   `PushNotifier` contract; queued via Laravel jobs (Redis/Horizon).
3. **Notification taxonomy + router:** the **earned-urgency** service that decides *who*
   gets paged and at *what tier* — specified in
   [05-notifications-earned-urgency.md](05-notifications-earned-urgency.md). This is the
   highest-judgment piece; it is a clinical-safety component, not plumbing.
4. **Event → push bridge:** subscribe the notifier to the existing broadcast events and the
   append-only `*_events` logs (transport/EVS/staffing) + Ops approval lifecycle, plus new
   server-side **evaluators** for threshold events (boarding ≥ 6, barrier aging, SLA breach,
   capacity deficit) that have no event today.

### Gap 3 — Inconsistent API auth & shape (→ build the BFF)
**Today:** ~106 endpoints, **no uniform envelope**; several operational routes (`cases`,
`blocks`, `rooms`, `providers`, three `analytics/*`, `improvement/*`) carry **only
`throttle`, no `auth`** — effectively public, including **unauthenticated OR-case
create/update**. PHI is interleaved with operational data.

**Required:** a versioned **mobile BFF** (`/api/mobile/v1/*`) — see §2. It is the *only*
surface mobile talks to: consistently `auth:sanctum`-gated, role-scoped, PHI-minimized,
aggregated per screen, with a uniform envelope. (Separately, the audit's finding that core
web endpoints lack `auth` should be remediated regardless of mobile — flagged to the
platform team as a security issue.)

---

## 2. The Mobile BFF (`/api/mobile/v1/*`)

A thin Laravel layer (controllers + Resources + a few aggregating services) that **reuses
existing models/services** but reshapes them for mobile. It does **not** re-implement
business logic — mutations delegate to the existing lifecycle services
(`OperationalActionLifecycleService`, the RTDC engine, the Transport/EVS/Staffing
`*OperationsService`s) so there is exactly one source of truth.

### Design rules
- **One screen → one call** where practical (aggregate the chatty web `/api` reads).
- **Uniform envelope:** `{ data, meta: { as_of, stale, version }, links: { web } }`.
- **Role-scoped:** every response filtered by the caller's role/assignment (RBAC, §3).
- **PHI-minimized:** patient identifiers are tokenized/initials by default; full PHI only on
  an explicit, authorized detail call and **never** in list/notification payloads.
- **ETag/`version`** on mutable resources for the conflict policy.
- **Cursor pagination** + `updated_since` for delta sync.

### Endpoint surface (v1) — the contract

The authoritative spec is [api-contract/hummingbird-bff.v1.yaml](../api-contract/hummingbird-bff.v1.yaml).
Summary:

| Group | Endpoint | Method | Backs | Delegates to |
|-------|----------|--------|-------|--------------|
| **Auth** | `/auth/token`, `/auth/token/refresh`, `/auth/token/revoke`, `/auth/change-password` | POST | login/refresh/logout | Sanctum + locked auth flow |
| **Me** | `/me` | GET | profile, role, workflow_preference, prefs | `User` |
| | `/me/preferences` | PUT | notif tiers, quiet hours, theme, default workflow | new `user_preferences` |
| **Devices** | `/devices`, `/devices/{id}` | POST/DELETE | push-token registry | `mobile_devices` |
| **Home** | `/home` | GET | role-aware home composition (one call) | aggregator |
| **For You** | `/foryou` | GET | the unified ranked action queue | `ForYouFeed` aggregator |
| | `/foryou/{itemId}/ack` | POST | acknowledge/dismiss | per-domain service |
| **RTDC** | `/rtdc/census`, `/rtdc/house` | GET | unit + house roll-up | RTDC engine |
| | `/rtdc/bed-requests`, `…/{id}/decision` | GET/POST | list + place | `BedRequest`/placement |
| | `/rtdc/barriers`, `…/{id}/resolve` | GET/POST | list + resolve | `Barrier` |
| | `/rtdc/huddles/{id}`, `…/steps` | GET/POST | huddle steps | huddle engine |
| **OR** | `/or/board`, `/or/cases/today`, `/or/cases/{id}` | GET | room board + cases | `ORCase`/`ORLog` |
| | `/or/cases/{id}/status` | POST | advance status | case service *(after store fix)* |
| | `/or/cases/{id}/safety-notes`, `…/milestones/{m}/ack`, `…/transport` | POST | safety/milestone/transport | respective models |
| **Ops** | `/ops/inbox` | GET | approvals/actions summary | `ops.*` |
| | `/ops/approvals/{id}/decision` | POST | approve/reject | `OperationalActionLifecycleService` |
| | `/ops/actions/{id}/{assign,start,complete}` | POST | action lifecycle | same |
| **Command** | `/command/house`, `/command/brief` | GET | strain index, Exec Brief | `CommandCenterController` |
| **Transport** | `/transport/queue`, `…/{id}`, `…/{id}/status`, `…/{id}/assign`, `…/{id}/handoff` | GET/POST | trip lifecycle | `TransportOperationsService` |
| **EVS** | `/evs/queue`, `…/{id}`, `…/{id}/status` | GET/POST | bed-turn lifecycle | `EvsOperationsService` |
| **Staffing** | `/staffing/requests`, `…/{id}/status` | GET/POST | request lifecycle | `StaffingOperationsService` |
| **ED** *(P3)* | `/ed/signals` | GET | boarding/diversion/LWBS/surge | Command/Analytics |
| **PI** *(P4)* | `/pi/pdsa`, `…/{id}/stage` | GET/POST | PDSA (needs new backend) | `PdsaCycle` |
| **Realtime** | `/realtime/config` | GET | Reverb host/key + channels to subscribe | broadcasting config |
| **Search** | `/search?q=` | GET | units/beds/rooms/providers | aggregator |

> Endpoints map 1:1 to the parity matrix ([01](01-feature-parity-matrix.md)). `P3+`/`NEW`
> rows depend on the corresponding net-new backend (ED actions, PI write paths, RTDC huddle
> action-items, diversion endpoint/broadcast).

---

## 3. Authorization / RBAC for mobile

**Today:** only `super-admin`/`admin` (Spatie) is a real gate; `workflow_preference` is a
*preference any user can switch*, not a permission; operational FormRequests are
`authorize: true` (open). That is acceptable for a shared-workstation web app where
"everyone reads the same truth," but **a personal mobile device that can *act* needs proper
action-authorization.**

**Required (additive policy layer — read stays broad, *write* gets scoped):**
- **Reads:** keep the web's broad, PHI-gated readability (house status is for everyone at
  their altitude). The BFF role-scopes *what's surfaced* but does not hard-deny glanceable
  status.
- **Writes:** introduce Laravel **Policies** so that, e.g., only the **assigned** transporter
  progresses a trip; only an **authorized approver** decides an operational action; only a
  **bed manager / charge role** places a bed or resolves a barrier. Back this with:
  - A real **assignment model** (the audit found `Barrier.owner` / `RtdcPlan.owner` are
    free-text strings, and there's **no user↔unit association**, so "assigned to me / my
    unit" isn't queryable). Add `user_id` FKs + a `user_unit` pivot so "For You" and
    notification routing can target individuals and units. **This is a prerequisite for both
    the For-You queue and notification routing.**
- **Token abilities/scopes:** issue Sanctum tokens with abilities matching the role so the
  device can't exceed its mandate even if the UI is wrong.

---

## 4. Real-time / broadcast work

- **Reuse** the existing Reverb broadcasts (`CensusUpdated`, `HuddleUpdated`,
  `BedMeetingUpdated`) — the mobile WS client subscribes to the same **public PHI-free**
  channels. Expose host/key/channels via `/api/mobile/v1/realtime/config`.
- **Add broadcasts** the audit found missing but mobile needs: **barriers do not broadcast**
  today (unlike census/huddle) — add `BarrierUpdated`; add a **diversion** endpoint +
  `DiversionUpdated` (model exists, no API/broadcast). Keep all new channels **PHI-free**
  (counts/ids only).
- **Silent push** (`content-available` / data message) on these same events to trigger
  background delta sync; **user-facing push** only for NOTIFY-tier (per the taxonomy).
- **Future PHI-on-wire** (if ever needed) requires `PrivateChannel` + token channel auth in
  `routes/channels.php` (currently intentionally empty) — gated behind Gap 1.

---

## 5. Server-side evaluators (for NOTIFY events with no native trigger)

Some high-value notifications have **no event today** and need scheduled evaluators
(Laravel scheduler + queued jobs):

| Evaluator | Fires | Source today |
|-----------|-------|--------------|
| ED boarding threshold (≥ 6 critical) | boarding crosses band | computed metric, no event |
| Boarder dwell > 4h since admit decision | per-boarder timer | net-new |
| Barrier aging / unresolved > N h | timer on `Barrier` | no event |
| Bed-request unplaced > N min | timer on `BedRequest` | no event |
| Transport/EVS/Staffing SLA (`needed_at` passed, `at_risk`) | timer on request | `at_risk` flag exists |
| Capacity deficit (`bed_need > 0`, available → 0) | census transition | derive from `CensusSnapshot` |
| Action expiring (`expires_at` − lead time) | timer on `OperationalAction` | `expires_at` exists |
| Data feed stale / metric qualified | freshness check | `SourceFreshness`/`DataQualityFinding` |
| Morning Executive Brief ready | schedule | Command Center |

Each evaluator emits into the **notification router** ([05](05-notifications-earned-urgency.md)),
which applies tiering, role/assignment routing, prefs, quiet hours, and escalation.

---

## 6. Backend bug-fixes the audit surfaced (fix before the dependent phase)

| Fix | Impact | Phase |
|-----|--------|-------|
| `ORCaseController@store` writes string `status` not `status_id` | OR case create is broken | before P2 |
| Analytics SQL references non-existent `or_cases.actual_start/end_time` | route via `orlog`+`case_metrics` | before P2 (if any OR metric tiles) |
| Reference endpoints filter `is_active` but column is `active_status` | reference lookups | before P2 |
| Operational web endpoints lack `auth` middleware | security (web + mobile) | P0 (remediate generally) |
| `Barrier.owner`/`RtdcPlan.owner` free-text; no `user_unit` | blocks "assigned to me" routing | P0/P1 (assignment model) |
| `RtdcPlan` (huddle action-items) has no API/UI | blocks huddle-companion | P2 |
| `DiversionEvent` has no endpoint/broadcast | blocks diversion notify | P3 |
| PI/PDSA write routes referenced but absent from `routes/web.php` | blocks PI mobile | P4 |

---

## 7. Summary — backend delivery order

```
P0  Auth tokens (additive) · device registry · push service skeleton · BFF scaffold ·
    assignment model (user_id FKs + user_unit) · RBAC policies · remediate open endpoints
P1  BFF: RTDC (census/beds/barriers), Transport, EVS · evaluators (SLA, capacity, barrier) ·
    BarrierUpdated broadcast · notification router v1
P2  BFF: OR (board/cases/safety/milestones) [+ store/column fixes] · Ops (approvals/actions) ·
    Command Center (house/brief) · huddle action-items API · WS config endpoint
P3  ED signals · Staffing · diversion endpoint+broadcast · regional transfer
P4  PI/PDSA write paths · integration-health alerts · simulation read
```
