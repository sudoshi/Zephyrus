# Hummingbird — Consolidated Implementation Plan

> **Historical planning baseline:** This 2026-06-26 plan is retained for design
> lineage and is no longer authoritative for architecture or execution status. The
> current execution authority is
> [ZEPHYRUS-HUMMINGBIRD-FUNCTIONAL-PARITY-AND-PATIENT-EXPERIENCE-PLAN-2026-07-19.md](ZEPHYRUS-HUMMINGBIRD-FUNCTIONAL-PARITY-AND-PATIENT-EXPERIENCE-PLAN-2026-07-19.md);
> [ADR-2026-07-19-generated-native-contracts.md](ADR-2026-07-19-generated-native-contracts.md)
> supersedes this document's KMP-runtime recommendation.
>
> **Native mobile companion to Zephyrus** (Android: Kotlin/Jetpack Compose · iOS:
> Swift/SwiftUI · originally proposed Kotlin Multiplatform core).
> It states the cross-cutting **invariants** once (Part II), then sequences the work as six
> self-contained **phases** (Part III), backed by a full parity matrix and reference
> appendices (Part IV). Deep-dive docs ([00–07](#document-map)) and the seven-stream code
> audit ([research/](research/)) provide field-level detail.
>
> **Status:** Planning v1 · 2026-06-26 · Bounded by the locked auth rules in
> [.claude/rules/auth-system.md](../../.claude/rules/auth-system.md) — all backend work is
> _additive_.

---

## Table of contents

- **Part I — Strategy & Context**
    - [1. Executive summary](#1-executive-summary)
    - [2. Why a companion, not a port](#2-why-a-companion-not-a-port)
    - [3. What the audit found](#3-what-the-audit-found)
    - [4. Personas — "all levels of the delivery system"](#4-personas--all-levels-of-the-delivery-system)
- **Part II — The Invariants** (hold across every phase)
    - [5. Architecture](#5-architecture-invariant)
    - [6. Real-time & offline model](#6-real-time--offline-model-invariant)
    - [7. The Mobile BFF & auth model](#7-the-mobile-bff--auth-model-invariant)
    - [8. Design-system parity](#8-design-system-parity-invariant)
    - [9. Earned-urgency notifications](#9-earned-urgency-notifications-invariant)
    - [10. Security & HIPAA gates](#10-security--hipaa-gates-invariant)
    - [11. The shared core every worker gets](#11-the-shared-core-every-worker-gets-invariant)
- **Part III — The Phased Plan**
    - [Phase 0 — Foundation](#phase-0--foundation)
    - [Phase 1 — Frontline workers & bed flow](#phase-1--frontline-workers--bed-flow)
    - [Phase 2 — Decisions & the OR board](#phase-2--decisions--the-or-board)
    - [Phase 3 — Awareness breadth](#phase-3--awareness-breadth)
    - [Phase 4 — Net-new backend features](#phase-4--net-new-backend-features)
    - [Phase 5 — Hardening & GA](#phase-5--hardening--ga)
- **Part IV — Reference**
    - [A. Full feature-parity matrix](#appendix-a--full-feature-parity-matrix)
    - [B. Backend change log](#appendix-b--backend-change-log)
    - [C. Risk register](#appendix-c--risk-register)
    - [D. Team & timeline](#appendix-d--team--timeline)
    - [E. Program definition of done](#appendix-e--program-definition-of-done)
    - [Document map](#document-map)

---

# Part I — Strategy & Context

## 1. Executive summary

Hummingbird is the **glance-and-act** surface of Zephyrus. The web app answers _"what is
happening across the hospital, and why?"_ on wall displays and workstations. Hummingbird
answers a sharper question for a worker mid-shift, in a hand, in three seconds:

> **"What needs _me_, right now — and can I do it in two taps?"**

One native app per platform adapts its home, its unified **"For You"** action queue, and its
notification defaults to the signed-in worker — from the **transporter** moving a patient to
the **CMO** glancing at house status between meetings.

**The headline decisions:**

| Decision       | Choice                                                     | Rationale                                                                            |
| -------------- | ---------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| UI             | Native per platform (Compose + SwiftUI)                    | Glanceability/feel are the product; native widgets, Live Activities, Critical Alerts |
| Shared code    | **KMP** domain + data core, bridged via SKIE               | Clinical/status logic is "defensible" — it must not drift between two codebases      |
| Backend access | A versioned **mobile BFF** (`/api/mobile/v1/*`)            | The web `/api` is chatty, inconsistently authed, PHI-rich                            |
| Auth           | **Sanctum tokens** (+ OIDC/PKCE path), _additive_          | Move off session cookies without touching the locked flow                            |
| Real-time      | **Push-first**, websocket-when-foregrounded, poll fallback | Battery + correctness; never hold a background socket                                |
| Offline        | Offline-_aware_ (cache reads, queue non-critical writes)   | Spotty hospital Wi-Fi; block safety-critical writes offline                          |
| Notifications  | Server-side **four-tier "earned-urgency" router**          | Alarm fatigue is a clinical-safety failure, not a UX nit                             |
| Design         | One **DTCG token source** → Compose + SwiftUI + CSS        | One design system, three platforms, zero drift                                       |

**The plan in one picture:**

```
P0 Foundation   ██████          auth · push · BFF · tokens · RBAC · assignment model
P1 Frontline      ██████        Transport · EVS · RTDC beds/barriers · For-You · notif v1
P2 Decisions        ████████    Ops approvals · Command/Brief · OR board · huddles
P3 Awareness            ██████  ED signals · Staffing · transfers · watch/widgets
P4 Net-new                ████████ PI/PDSA · ED actions · integration health
P5 Hardening/GA               ██████ security + clinical-safety review · perf · rollout
```

_~9–12 months to GA for a focused team; usable internal pilots from end of P1. Phases P1–P2
can overlap once the BFF pattern is set._

**The sequencing logic (why this order):** three platform gaps gate _everything_, so they
come first (P0). Then we build on **live data with low backend risk** for fast value —
RTDC, Ops approvals, Transport, EVS all run on existing endpoints (P1–P2). Features that
require **net-new backend** (ED clinician actions, Process Improvement) are deferred (P4)
because their web surfaces are mock/stub today; we still ship their _glanceable_ signals
earlier where the data already exists (P3). Hardening and the safety/security gates that GA
legally depends on close the program (P5).

## 2. Why a companion, not a port

Zephyrus is a dense, instrument-rich **operations bridge** built for multi-panel study. Most
of that does not belong on a phone under time pressure. Every web capability is triaged
against the glance-and-act question into one treatment:

| Flag       | Meaning                                 | Mobile treatment                         |
| ---------- | --------------------------------------- | ---------------------------------------- |
| **GLANCE** | Read at a glance; situational awareness | Home tiles, widgets, Live Activities     |
| **ACT**    | A worker would _act_ on it from a phone | First-class flows + notification actions |
| **NOTIFY** | A discrete event worth a push           | The earned-urgency taxonomy              |
| **WEB**    | Too analytical/dense for phone          | Deep-link to web; not rebuilt            |

> **Rule of thumb:** if a task needs more than ~3 visible panels, a keyboard, or sustained
> study (utilization analytics, process mining, simulation authoring, blueprint modeling,
> admin), it **stays on the web** and Hummingbird offers a one-tap deep link.

This plan honors the Zephyrus canon on mobile: **Rigorous · Composed · Defensible**;
**earned urgency** (color and pushes are rationed); **status never by color alone**;
**dark-default**, `tabular-nums`, gold focus; **replace the EHR, don't reskin it**. It is not
a shrunk dashboard, not a consumer health app, and never an alarm-fatigue pager.

## 3. What the audit found

A seven-stream parallel audit (95 models, ~106 API endpoints, all four workflows + supporting
domains + platform) produced five findings that shape the entire sequence.

**Per-domain readiness (this drives phasing):**

| Domain                         |   Data model   |             API contract             |        Live data         |     Real-time     | Verdict                         |
| ------------------------------ | :------------: | :----------------------------------: | :----------------------: | :---------------: | ------------------------------- |
| **RTDC**                       |    ✅ rich     |           ✅ `/api/rtdc/*`           |     ✅ event-sourced     | ✅ **Reverb WS**  | **Flagship — build first**      |
| **Ops / AI approvals**         |   ✅ `ops.*`   |           ✅ `/api/ops/*`            |   ✅ production-grade    |        ⚪         | **Reuse endpoints verbatim**    |
| **Transport / EVS / Staffing** |       ✅       | ✅ `/api/{transport,evs,staffing}/*` |      ✅ event logs       |        ⚪         | **Textbook mobile workers**     |
| **Perioperative**              |    ✅ rich     |        ✅ `/api/cases,blocks`        |    ⚠️ mostly mock UI     | ❌ (local timers) | **Ready data, no surface**      |
| **Command Center**             |       ✅       |      ✅ `/api/command-center/*`      |            ✅            |        ⚪         | Strain index + Exec Brief       |
| **Emergency Dept**             |  ⚠️ 2 tables   |          ❌ 0 ED endpoints           | ⚠️ via Command/Analytics |        ❌         | **Glance now, act later (NEW)** |
| **Process Improvement**        | ⚠️ models only |               ❌ stub                |      ❌ empty stubs      |        ❌         | **Net-new backend**             |

**The three platform gaps that gate everything:**

1. **No mobile auth.** Session-cookie only; **Sanctum is installed but dormant** (`User`
   lacks `HasApiTokens`, zero `auth:sanctum` routes). OIDC/Authentik exists but also lands a
   web session. → token auth is **net-new** (additive).
2. **No push infrastructure.** Zero APNs/FCM, no device registry, no notifier. Only Reverb
   websockets (foreground) + one demo SSE. → push is **net-new**.
3. **Inconsistent API auth & shape.** Several operational endpoints (`cases`, `blocks`,
   `rooms`, `providers`, analytics, `improvement/*`) carry **no `auth` middleware**; no
   uniform envelope; PHI interleaved with operational data. → a **BFF** is required.

Full detail: [research/](research/) (`02-rtdc`, `03-perioperative`, `04-improvement-and-ops`,
`05-platform-auth-realtime-design`, `06-supporting-domains-and-api`, `01-emergency-department`,
`07-mobile-best-practices`).

## 4. Personas — "all levels of the delivery system"

One app, **role-adaptive home**. Zephyrus treats its audience as one product switched by a
`workflow_preference` (a _preference_, not a hard permission); Hummingbird honors that and
extends down to the frontline mobile worker the web under-serves.

| #   | Persona                              | Altitude         | Phase | Home emphasis                        |
| --- | ------------------------------------ | ---------------- | :---: | ------------------------------------ |
| P1  | **Transporter / Porter**             | Frontline worker |   1   | My trips + available jobs            |
| P2  | **EVS Technician**                   | Frontline worker |   1   | My bed-turns + next dirty bed        |
| P3  | **Bedside / Charge Nurse**           | Frontline unit   |  1–2  | My unit: census, barriers, incoming  |
| P4  | **OR Circulating / Charge Nurse**    | Frontline OR     |   2   | Live room board + my cases           |
| P5  | **Nursing Supervisor / Bed Manager** | Ops leader       |   1   | House bed-need + pending requests    |
| P6  | **Patient-Flow / Capacity Lead**     | Ops leader       |   2   | Capacity vs demand + approvals inbox |
| P7  | **Perioperative Manager**            | Ops leader       |  2–3  | OR day at a glance                   |
| P8  | **PI / Quality Lead**                | Ops leader       |   4   | My PDSA cycles + barrier trends      |
| P9  | **Executive (CMO/COO/CNO)**          | Executive        |   2   | One quiet strain index + Brief       |
| P10 | **Staffing Coordinator**             | Ops leader       |   3   | Open requests + below-safe units     |

**The altitude model** (how "earned urgency" expresses per level): a frontline worker's home
is a **task list**; a unit's home is **my unit / my cases**; an ops leader's home is **the
house / my domain**; an executive's home is a **single strain index** that stays quiet until
it shouldn't. Full per-persona journeys: [02-core-functionality-by-role.md](reference/02-core-functionality-by-role.md).

---

# Part II — The Invariants

These decisions are constant across all phases. They are stated once here; each phase
references them rather than re-deciding.

## 5. Architecture _(invariant)_

**Native UI per platform over a shared KMP domain/data core.** This single decision must be
made **now** — committing to shared Kotlin logic puts Kotlin/Native in the iOS build, and
deciding late forces an expensive re-architecture. If the org rejects KMP, the floor is two
fully-separate apps bound by the [API contract](api-contract/) + a shared conformance test
spec.

```
PRESENTATION (native)   Android: Compose + ViewModel (MVVM-UDF; MVI for dense boards)
                        iOS:     SwiftUI + @Observable (TCA only for true state machines)
                        → widgets · Live Activities/Dynamic Island · Watch/Wear

SHARED (KMP)            domain   models · use-cases · status rules · urgency scoring · validation
                        data     repositories · Ktor BFF client · SQLDelight cache · outbox ·
                                 sync engine · Reverb/Pusher realtime · token store
                        tokens   generated from DTCG (see §8)

PLATFORM (expect/actual) Keychain/Keystore · biometrics · APNs/FCM tokens · BGTasks/WorkManager
```

- **Android:** Compose (Material3 themed to Zephyrus tokens, _not_ default Material), Hilt,
  Coroutines/Flow, Ktor, SQLDelight, DataStore, WorkManager, FCM, Glance widgets, Wear.
- **iOS:** SwiftUI + Observation, Swift Concurrency, Ktor-via-shared (or URLSession actual),
  SQLDelight, Keychain, BGTaskScheduler, APNs (+ Critical Alerts, ActivityKit), WidgetKit,
  watchOS. **SKIE** generates idiomatic Swift over the KMP module.
- **Feature modules** are vertical slices depending only on `shared/domain` + `shared/data` +
  `core-ui`. The **For-You** feature composes every domain through one `ForYouFeed` use-case
  in `shared/domain` — the single home of the earned-urgency ranking.

Module/repo structure and the full stack rationale: [03-architecture.md](reference/03-architecture.md).

## 6. Real-time & offline model _(invariant)_

**Three-mode real-time** (mirrors the web's Reverb posture and extends it for battery):

| App state             | Transport                       | Behavior                                                                                |
| --------------------- | ------------------------------- | --------------------------------------------------------------------------------------- |
| Foreground, screen on | **Reverb WS** (Pusher protocol) | Live tiles; **re-snapshot all visible queries on (re)connect** — Reverb does not replay |
| Background / locked   | **Push (APNs/FCM)**             | Silent push → WorkManager/BGTask delta-sync; visible push for NOTIFY events             |
| No connectivity       | Poll on resume + cache          | Stale badge; reconcile outbox                                                           |

We **never hold a websocket in the background**. Public WS channels are **PHI-free** (counts/
ids only); any future PHI-on-wire needs `PrivateChannel` + token channel auth.

**Offline-aware, not offline-first:**

- **Reads:** cache-then-network; emit cached immediately with an **as-of timestamp**, refresh,
  re-emit; on failure keep cache + show a **stale badge** (never a blank screen).
- **Non-critical writes** (acknowledge, claim routine job, routine status): optimistic local
  apply + **outbox**; flush on reconnect.
- **Safety-critical writes** (approve an action, place a bed, complete an isolation bed-turn,
  advance a case past a clinical gate): **require connectivity**; if offline, the action is
  **disabled with an explicit reason** — never silently queued.
- **Conflicts:** last-write-wins + field-merge + server `version`/ETag; **409** surfaces
  "changed since you loaded — review," never a blind overwrite. No CRDTs.

## 7. The Mobile BFF & auth model _(invariant)_

**A versioned BFF (`/api/mobile/v1/*`) is the only surface the apps talk to.** It reuses
existing models/services and **delegates all mutations** to the existing lifecycle services
(`OperationalActionLifecycleService`, the RTDC engine, the Transport/EVS/Staffing
`*OperationsService`s) — it reshapes, it does not re-implement. Rules:

- **One screen → one call** where practical; **uniform envelope**
  `{ data, meta:{as_of,stale,version}, links:{web} }`; **`auth:sanctum` on everything**;
  **role-scoped** and **PHI-minimized** (tokenized/initials in lists; full PHI only on an
  explicit authorized detail call, **never** in lists or notifications); ETag/cursor/
  `updated_since` for concurrency and delta sync.

**Auth (additive — never touches the locked flow):** add `HasApiTokens` to `User`; issue
**Sanctum personal access tokens** with role-matched abilities; provide an **OIDC+PKCE** path
reusing Authentik for SSO orgs. The token endpoints **honor `must_change_password`** by
returning a scoped change-challenge (the temp-password/Resend/superuser flow is preserved
verbatim). Tokens live in Keychain/Keystore gated by biometrics; short-lived access +
rotating refresh; idle auto-lock; server-side revoke for remote wipe.

**RBAC:** reads stay broad (house status is for everyone at their altitude, PHI-gated);
**writes** get Laravel **Policies** so a device can only act within role/assignment (only the
assigned transporter progresses a trip; only an authorized approver decides an action). This
requires the **assignment model** (§Phase 0). The contract: [api-contract/hummingbird-bff.v1.yaml](api-contract/hummingbird-bff.v1.yaml)
(OpenAPI 3.1, validated). Full spec: [04-backend-requirements.md](reference/04-backend-requirements.md).

## 8. Design-system parity _(invariant)_

**One DTCG token source → three platforms, verified non-divergent in CI.** The web keeps its
Tailwind setup; [design-tokens/tokens.json](design-tokens/tokens.json) generates Compose +
SwiftUI equivalents via Style Dictionary and a CI job **diffs** resolved values against
`tailwind.config.js` + `.impeccable/design.json` — a mismatch fails the build.

The tokens encode the Zephyrus canon: the **Two-System Rule** (operational blue/slate
`#2563EB`/`#3B82F6` vs. brand crimson `#9B1B30` + gold `#C9A227` focus-only), the **rationed
status ramp** (teal `#2DD4BF` / amber `#E5A84B` / coral `#E85A6B` / sky `#60A5FA` dark),
**Figtree weights 400/500/600 only**, dark-default, the 4px grid. Signature components
(`KpiTile` with its 3px status stripe + arrow + label, `StatusChip` dot-not-color-alone,
`Panel` quiet-lift) are built **once per platform** from these tokens. Pipeline + samples:
[design-tokens/](design-tokens/).

## 9. Earned-urgency notifications _(invariant)_

A notification is the most expensive signal Hummingbird can send. The taxonomy is a
**clinical-safety component** with acceptance criteria — a mis-tuned one causes alarm fatigue
(staff silence the app and miss the real breach). **Four tiers:**

| Tier                   | Meaning                                    | iOS                          | Android                   | Quiet hours          |
| ---------------------- | ------------------------------------------ | ---------------------------- | ------------------------- | -------------------- |
| **T1 Critical breach** | Threshold breach / safety event, act _now_ | Critical Alert (entitlement) | Full-screen intent        | Overrides (capped)   |
| **T2 Actionable**      | Assigned to you to act on soon             | Time-Sensitive + actions     | High-importance + actions | Deferred unless STAT |
| **T3 Awareness**       | Worth knowing, not urgent                  | Passive                      | Default/low               | Silenced             |
| **T4 Digest**          | Periodic summary                           | Scheduled                    | Scheduled low             | Silenced             |

**Principles:** severity earned per event (most state changes are GLANCE, silent); **route to
the person who can act** (needs the assignment model); the **action is in the notification**
(Approve/Claim/Acknowledge from the lock screen, zero app-open); respect the human (per-tier
opt-in, **quiet hours**, **per-shift budget**, dedupe/coalesce); **no PHI ever** (generic
copy, CI payload linter); **escalate, don't repeat** (unacked T1/T2 → re-deliver → next
responsible → web command center, fully logged). The complete event→tier→audience→action
taxonomy: [05-notifications-earned-urgency.md](reference/05-notifications-earned-urgency.md).

## 10. Security & HIPAA gates _(invariant)_

Security is a **release gate**, not a backlog. The cheapest, highest-impact wins, done first:
**no PHI in notifications/logs/app-switcher snapshots**, **biometric-gated token storage +
auto-lock**, **TLS + cert pinning with no PHI on public channels**. Plus: encrypted local
cache (SQLCipher), `FLAG_SECURE`/privacy-view, jailbreak/root + attestation (App Attest/Play
Integrity), MDM/remote-wipe, action-level RBAC, tamper-evident audit logging, BAA-covered or
PHI-free SDKs only, and WCAG 2.2 AA (status-never-by-color, dynamic type, VoiceOver/TalkBack,
44pt targets). The seven non-negotiable **release-gate criteria** (incl. _zero PHI leaves the
boundary_, _auth additivity proven by regression suite_, _named security + clinical-safety
sign-off_) are in [06-security-hipaa.md §9](reference/06-security-hipaa.md#9-release-gate-acceptance-criteria)
and are written into the program [Definition of Done](#appendix-e--program-definition-of-done).

## 11. The shared core every worker gets _(invariant)_

Regardless of role/phase, every persona's app is built from the same primitives — only the
**feed sources and ranking weights** change:

1. **Secure entry** — token login, biometric unlock, auto-lock, forced-change honored.
2. **Role-aware home** — the altitude's most important truth, glanceable; workflow switcher.
3. **The "For You" queue** — one prioritized, cross-domain list of what needs _this_ worker,
   ranked by earned-urgency score, each card carrying its **primary action inline**.
4. **Rationed, actionable notifications** (§9).
5. **House/unit status glance** — role-scoped, PHI-gated; tile + widget + (phased) Live
   Activity/complication.
6. **Directory & search** — the web's ⌘K as a search screen.
7. **Profile/preferences & deep links** — default workflow, notification tiers, quiet hours,
   theme; "Open in Zephyrus" for every WEB-deferred path.

This is why one app spans the porter and the CMO: shared primitives, role-tuned feed.

---

# Part III — The Phased Plan

Each phase is a self-contained work package: **thesis · why now · backend · mobile · features
& personas · notifications · exit criteria**. All phases assume the Invariants (Part II).

## Phase 0 — Foundation

**~6–8 weeks · the unblock**

**Thesis.** A signed-in worker on both platforms can authenticate with biometrics, hit a
secured BFF, receive a push, and see one real RTDC tile. Nothing user-facing ships, but
everything after depends on it.

**Why now.** The three gating gaps (§3) block 100% of features. Building them first is the
only rational sequence.

**Backend**

- Sanctum token auth: `HasApiTokens` on `User`; `POST /api/auth/token{,/refresh,/revoke}` +
  `/auth/change-password` — additive, honoring `must_change_password`. OIDC+PKCE path stubbed.
- `mobile_devices` registry + push service (APNs `.p8` HTTP/2 + FCM v1) behind a
  `PushNotifier` contract; queued via Horizon.
- **BFF scaffold** (`/api/mobile/v1/*`): uniform envelope, role-scoping, PHI-minimization,
  `/me`, `/realtime/config`, `/rtdc/census`.
- **Assignment model** — `user_id` FKs on owners (today `Barrier.owner`/`RtdcPlan.owner` are
  free-text) + a `user_unit` pivot. _Prerequisite for both the For-You queue and notification
  routing — without it, "assigned to me / my unit" is not queryable._
- RBAC Policy layer; **remediate the auth-less operational endpoints** (a standing security
  issue regardless of mobile).

**Mobile (both platforms)**

- Repo + CI scaffold; **KMP `shared`** skeleton (`domain`/`data`/`platform`); SKIE bridge.
- **Design-token pipeline** wired (DTCG → Compose/SwiftUI); `core-ui` primitives (`KpiTile`,
  `StatusChip`, `Panel`). _(Already started — [design-tokens/](design-tokens/).)_
- Auth flow: token + **biometric unlock** + auto-lock + Keychain/Keystore; cert pinning.
- BFF client (Ktor), SQLDelight cache, outbox skeleton, push registration.

**Features / personas.** None user-facing (foundation).

**Notifications.** Delivery pipeline stood up; one test push.

**Exit criteria.** Auth regression suite green (locked flow unchanged); a device receives a
test push; one live `/rtdc/census` tile renders on iOS _and_ Android via the BFF; security
checklist §1–§3 pass.

## Phase 1 — Frontline workers & bed flow

**~6–8 weeks · highest, fastest value**

**Thesis.** Transporters and EVS techs run their jobs from the phone; bed managers place beds
and clear barriers on the move; everyone gets a working **For-You** queue and rationed pushes.

**Why now.** All **LIVE** endpoints, clean lifecycles, lowest backend risk, and the clearest
mobile win (transporter/EVS _are_ mobile users; bed managers want placement off-workstation).

**Backend**

- BFF: RTDC (`/rtdc/census`, `/house`, `/bed-requests` + `/{id}/decision`, `/barriers` +
  `/{id}/resolve`), Transport (`/queue`, `/{id}/status|assign|handoff`), EVS (`/queue`,
  `/{id}/status`).
- Add **`BarrierUpdated`** broadcast (barriers don't broadcast today, unlike census/huddle).
- **Server-side evaluators** for events with no native trigger: SLA breach (`needed_at`/
  `at_risk`), capacity deficit (`bed_need>0`, available→0), barrier aging, unplaced bed
  request. Wire into the **notification router v1** (tiers, routing via assignment model,
  quiet hours, budget).

**Mobile**

- **Transport (P1):** claim → run trip (17-status) → structured handoff; STAT push.
- **EVS (P2):** claim → start (SOP/PPE for isolation) → complete (notifies bed manager).
- **RTDC beds & barriers (P3/P5):** live unit census + safe capacity (Reverb WS); pending bed
  requests + **transparent placement decision** (accept/edit/reject); log/resolve barriers.
- **For-You queue v1** across these domains; first **widget / Live Activity** (active trip;
  unit/house status glance).

**Features / personas.** P1 Transporter, P2 EVS, P3 Charge Nurse (beds/barriers), P5 Bed
Manager — _activated_.

**Notifications.** STAT transport/isolation bed-turn assigned (T1); new pending bed request,
unplaced bed, aging barrier, SLA at-risk (T2); bed-turn completed (T3).

**Exit criteria.** A transporter claims a STAT job from the lock screen in **< 10 s**; a bed
manager places a bed from a push; evaluators fire correctly **within the per-shift budget**.

## Phase 2 — Decisions & the OR board

**~8–10 weeks · the marquee ops-leader + OR phase**

**Thesis.** Ops leaders approve and assign operational actions on the go; executives get a
single quiet strain screen + Brief; OR staff get a live board, case tracking, and safety-note
SLAs.

**Why now.** Ops approvals reuse `/api/ops/*` **verbatim** (highest value, near-zero backend),
and the OR data/contract already exist (needs only three bug-fixes). This is the
decision-making heart of the product.

**Backend**

- BFF: Ops (`/ops/inbox`, `/approvals/{id}/decision`, `/actions/{id}/{assign,start,complete}`)
  delegating to `OperationalActionLifecycleService`; Command Center (`/command/house`,
  `/brief`); OR (`/or/board`, `/cases/today`, `/cases/{id}`, `/{id}/status`, safety-notes,
  milestones, transport).
- **OR backend status:** Appendix B supersedes the early audit bug list: `status_id` and
  `active_status` fixes are done, and the `or_cases.actual_start/end_time` finding was a false
  positive. Remaining OR mobile work is the write/performance BFF contract plus feature UX.
- Add the **huddle action-items API** (`RtdcPlan` table exists with no API/UI today).

**Mobile**

- **Ops approvals (P6):** approve/reject/assign/start/complete + agent-inbox glance tiles.
- **Command Center / Exec Brief (P9):** strain index (0–4), hero KPIs with trust badges, the
  server-composed brief; sparse exec notifications.
- **Perioperative (P4/P7):** live room board + my cases + single-case tracking; advance
  status; safety-note SLA acknowledge; pre-op milestone ack; transport-to-OR.
- **RTDC huddles (P3/P6):** unit-huddle steps (WS-synced) + huddle action-items.
- Dynamic Island / Live Activity for room board + house strain; **iOS Critical Alerts +
  Android full-screen-intent wired for T1 only** (apply for entitlements now — they gate GA).

**Features / personas.** P4 OR Nurse, P6 Capacity Lead, P7 Periop Manager, P9 Executive —
_activated_; P3 gains huddles.

**Notifications.** Recommendation awaiting approval, action expiring/overdue, safety-note SLA,
pre-op milestone incomplete (T2; Crit safety-note + house-status escalation T1); case delayed,
first-case late start, cancellation (T2/T3).

**Exit criteria.** A capacity lead approves an action from a notification; an OR nurse
acknowledges an overdue safety note; the exec home is a single quiet strain screen that
escalates correctly.

## Phase 3 — Awareness breadth

**~6–8 weeks**

**Thesis.** The glanceable signals that already exist as data — ED boarding/diversion/surge,
staffing gaps — reach the right leaders; at-a-glance coverage extends to watch and widgets.

**Why now.** These are **GLANCE/NOTIFY** built on existing computed metrics; they broaden
situational awareness without the net-new backend that ED _actions_ and PI need.

**Backend**

- BFF: ED signals (`/ed/signals` — boarding, diversion, LWBS, surge from Command/Analytics) if
  still needed as a dedicated mobile endpoint; Staffing is already exposed as
  `/staffing/overview` plus `/staffing/requests/{id}/fill`; regional/inter-facility transfer.
- **Diversion endpoint + `DiversionUpdated` broadcast** (model exists, no API/broadcast today).
- Evaluators: ED boarding band (≥6), ESI 1–2 LWBS, surge; staffing below-minimum-safe.

**Mobile**

- **ED glanceable signals (P9/P5):** boarding, diversion, LWBS, surge tiles; ESI 1–2 LWBS as
  T1.
- **Staffing (P10):** open requests, below-safe alerts, create/source/assign/fill.
- **Watch / Wear** complications for house/unit status; widget breadth.

**Features / personas.** P10 Staffing Coordinator — _activated_; P5/P9 gain ED awareness.

**Notifications.** ED diversion active, ESI 1–2 LWBS, unit below minimum-safe (T1); ED
boarding band crossing, boarder dwell >4h, surge critical, staffing unfilled/escalated (T2).

**Exit criteria.** ED breaches page the right leaders within budget; a staffing coordinator
fills a gap from mobile; glance surfaces cover house status across widgets/watch.

## Phase 4 — Net-new backend features

**~8–10 weeks · backend-led**

**Thesis.** The domains that are mock/stub on the web today get real backend, then their
mobile-worthy slices.

**Why now.** These require **net-new backend writes** (PDSA stage advancement and ED clinician
write paths are not implemented, even though mobile now has PDSA/opportunity reads). Doing them
last lets the high-value live-data features ship first and de-risks the net-new work.

**Backend**

- Build the missing **PI/PDSA** write paths; **ED boarder-dwell** evaluator and (if scoped) a
  net-new ED clinician write path; **integration-health** alerts (connector-down, dead-letter
  spike); simulation **read** (results/promoted recommendations — authoring stays web).

**Mobile**

- **PI/PDSA (P8):** cycle ownership + stage advance + barrier ack.
- **ED actions:** boarder-dwell breaches; live-board/triage acknowledgements _if_ the write
  path exists.
- **Integration health** glance + alerts for ops/admin.

**Features / personas.** P8 PI/Quality Lead — _activated_; ED gains limited actions.

**Notifications.** PDSA stage due, barrier assigned (T2); boarder dwell >4h (T2); connector
down / dead-letter spike (T2/T3, ops/admin).

**Exit criteria.** PI leads manage PDSA on mobile; ED dwell breaches notify; ops sees feed
health.

## Phase 5 — Hardening & GA

**~4–6 weeks**

**Thesis.** The app passes its safety, security, performance, and accessibility gates and
rolls out behind flags.

**Work**

- Full **security + clinical-safety reviews** (notification-taxonomy sign-off); penetration
  test; **PHI-leak audit**; accessibility audit (WCAG 2.2 AA).
- Performance/battery tuning (WS lifecycle, background sync, BFF payload sizes).
- Offline-edge hardening (409/conflict UX, outbox reconciliation, stale-badge correctness).
- Store readiness (privacy nutrition labels, **Critical Alerts entitlement** approval, data-
  safety form); pilot → phased rollout behind feature flags.

**Exit criteria.** All [release-gate criteria](#appendix-e--program-definition-of-done) pass;
pilot units validate time-to-act and notification budgets; **GA**.

---

# Part IV — Reference

## Appendix A — Full feature-parity matrix

Maps **every** web data element/function to a mobile treatment, satisfying _"map to all data
elements and functionality."_ `GLANCE/ACT/NOTIFY/WEB` · backend `LIVE/MOCK/NEW/WS` · phase.
Field-level detail per domain: [01-feature-parity-matrix.md](reference/01-feature-parity-matrix.md) and
[research/](research/).

### RTDC — Real-Time Demand & Capacity _(flagship)_

| Capability                                                          | Treatment         | Backend    | Phase |
| ------------------------------------------------------------------- | ----------------- | ---------- | ----- |
| Unit census + acuity-adjusted safe capacity                         | GLANCE·NOTIFY     | LIVE·WS    | 1     |
| Hospital bed-need roll-up (net/deficit)                             | GLANCE·NOTIFY     | LIVE·WS    | 1     |
| Bed (status/isolation/gender)                                       | GLANCE            | LIVE       | 1     |
| Bed request — list / **create**                                     | GLANCE·ACT·NOTIFY | LIVE       | 1     |
| Bed placement decision — **accept/edit/reject** (transparent score) | ACT               | LIVE       | 1     |
| Barrier to discharge — list / **log / resolve**                     | GLANCE·ACT·NOTIFY | LIVE       | 1     |
| Huddle (unit/service/global) + steps                                | GLANCE·ACT        | LIVE·WS    | 2     |
| Huddle **action items** (owner/due)                                 | ACT·NOTIFY        | NEW        | 2     |
| Discharge prediction / reconciliation / GMLOS                       | GLANCE            | LIVE       | 2     |
| Care-journey milestone                                              | GLANCE            | LIVE       | 2     |
| Diversion event                                                     | GLANCE·NOTIFY     | NEW        | 3     |
| Discharge readiness / priorities                                    | GLANCE·ACT        | NEW (mock) | 3     |
| RTDC deep analytics / trends                                        | WEB               | MOCK       | —     |

### Perioperative _(ready data, greenfield surface)_

| Capability                                                        | Treatment         | Backend            | Phase |
| ----------------------------------------------------------------- | ----------------- | ------------------ | ----- |
| OR case (service/surgeon/room/status)                             | GLANCE·ACT·NOTIFY | LIVE               | 2     |
| Wheels clock (ORLog, 13 timestamps)                               | GLANCE            | LIVE               | 2     |
| Case status — **advance** (Sched→InProg→Delay→Comp)               | ACT·NOTIFY        | LIVE _(fix store)_ | 2     |
| Room status board (derived)                                       | GLANCE·NOTIFY     | LIVE               | 2     |
| Case timing (progress %/variance)                                 | GLANCE·NOTIFY     | LIVE               | 2     |
| Safety note — **create/ack** (SLA 15/30/60/120m)                  | GLANCE·ACT·NOTIFY | LIVE               | 2     |
| Pre-op milestone — **ack** (H&P/Consent/Labs)                     | ACT·NOTIFY        | LIVE               | 2     |
| Case transport — ready/**complete**                               | ACT·NOTIFY        | LIVE               | 2     |
| Provider directory / reference data                               | GLANCE / lookup   | LIVE               | 2     |
| Block/OR/primetime/turnover/provider/service analytics; forecasts | WEB               | LIVE/MOCK          | —     |

### Ops / AI & Command Center _("approvals on the go")_

| Capability                                                   | Treatment         | Backend | Phase |
| ------------------------------------------------------------ | ----------------- | ------- | ----- |
| Recommendation (title/risk/rationale)                        | GLANCE·NOTIFY     | LIVE    | 2     |
| Operational action lifecycle (draft→…→completed, expires+8h) | GLANCE·ACT·NOTIFY | LIVE    | 2     |
| **Approve / reject** approval ★                              | ACT·NOTIFY        | LIVE    | 2     |
| Assign / start / complete (override/expire P3)               | ACT               | LIVE    | 2/3   |
| Agent inbox summary                                          | GLANCE            | LIVE    | 2     |
| House strain/surge index (0–4) + hero KPIs + 24h forecast    | GLANCE·NOTIFY     | LIVE    | 2     |
| Executive Brief (situation/plan/impact/confidence)           | GLANCE·NOTIFY     | LIVE    | 2     |
| Intervention/metric trust/lineage/freshness                  | GLANCE            | LIVE    | 3     |
| Data-quality finding (stale feed)                            | GLANCE·NOTIFY     | LIVE    | 3     |
| Simulation author / ops graph / process mining               | WEB               | LIVE    | —     |

### Emergency Department _(glance now, act later)_

| Capability                                                     | Treatment     | Backend                      | Phase |
| -------------------------------------------------------------- | ------------- | ---------------------------- | ----- |
| ED boarding count (≤6) · LWBS % · door-to-provider/LOS · surge | GLANCE·NOTIFY | LIVE (via Command/Analytics) | 3     |
| Active diversion · ESI 1–2 LWBS (safety)                       | GLANCE·NOTIFY | LIVE/NEW                     | 3     |
| Boarder dwell >4h since admit decision                         | NOTIFY        | NEW                          | 4     |
| Live ED board / triage / disposition                           | ACT           | NEW                          | 4+    |
| ED flow/wait/resource/acuity/arrival analytics                 | WEB           | MOCK                         | —     |

### Transport _(mobile worker)_ · EVS _(mobile worker)_ · Staffing

| Capability                                                            | Treatment         | Backend | Phase |
| --------------------------------------------------------------------- | ----------------- | ------- | ----- |
| Transport request + 17-status lifecycle; **claim/progress/assign**    | GLANCE·ACT·NOTIFY | LIVE    | 1     |
| Transport **structured handoff** (to/summary/docs/risks)              | ACT·NOTIFY        | LIVE    | 1     |
| Regional / inter-facility transfer                                    | GLANCE·ACT        | LIVE    | 3     |
| EVS bed-turn + 5-status; **claim/start/complete**; isolation SOP      | GLANCE·ACT·NOTIFY | LIVE    | 1     |
| Staffing plan (gap/min-safe) + request; **create/source/assign/fill** | GLANCE·ACT·NOTIFY | LIVE    | 3     |

### Process Improvement · Patient Flow/FHIR/Integration · Platform

| Capability                                                                      | Treatment             | Backend    | Phase          |
| ------------------------------------------------------------------------------- | --------------------- | ---------- | -------------- |
| PDSA cycle (plan/do/study/act) — **own/advance**; barrier ack                   | GLANCE·ACT·NOTIFY     | NEW        | 4              |
| Bottleneck / root-cause / process mining / 4D navigator                         | WEB                   | MOCK       | —              |
| Flow encounter/event/occupancy; patient identity; FHIR; ambient                 | (feeds census/board)  | LIVE       | 1–2 (indirect) |
| Integration source/health/connector; dead-letter                                | GLANCE·NOTIFY (admin) | LIVE       | 4              |
| Facility blueprint / space modeling                                             | WEB                   | LIVE       | —              |
| Auth/login · biometric · workflow switcher · profile/prefs · search · directory | ACT·GLANCE            | LIVE + NEW | 0–2            |
| Admin (users, auth providers)                                                   | WEB                   | LIVE       | —              |

**Coverage assertion.** Every audited model maps to a row above or its domain's WEB bucket.
The domains carrying the most **NEW** backend (ED actions, PI/PDSA, RTDC huddle action-items
& discharge-readiness) are precisely those that are _mock/stub on the web today_ — net-new
product, not a mobile gap — which is why they land in P3–P4.

## Appendix B — Backend change log

Consolidated additive backend work (detail: [04-backend-requirements.md](reference/04-backend-requirements.md)):

| Area                | Work                                                                                                                                                                                                                                                                                                                        | Phase |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- |
| **Auth**            | `HasApiTokens`; `/auth/token{,/refresh,/revoke}`, `/auth/change-password` (honor `must_change_password`); OIDC+PKCE path; token abilities                                                                                                                                                                                   | 0     |
| **Push**            | `mobile_devices` registry; APNs `.p8` + FCM v1 `PushNotifier`; notification router                                                                                                                                                                                                                                          | 0→1   |
| **BFF**             | `/api/mobile/v1/*` scaffold; per-domain endpoints rolled out P1–P4; uniform envelope; PHI-minimization                                                                                                                                                                                                                      | 0–4   |
| **Assignment/RBAC** | `user_id` FKs on owners + `user_unit` pivot; Laravel Policies; remediate auth-less endpoints                                                                                                                                                                                                                                | 0     |
| **Broadcasts**      | `BarrierUpdated` (P1); `DiversionUpdated` (P3); WS config endpoint                                                                                                                                                                                                                                                          | 1–3   |
| **Evaluators**      | SLA / capacity deficit / barrier aging / unplaced bed (P1); ED boarding/LWBS/surge, staffing min-safe (P3); boarder dwell, integration health (P4)                                                                                                                                                                          | 1–4   |
| **Bug fixes**       | OR `store` → `status_id` ✅ done; reference `is_active` → `active_status` ✅ done (`services`/`rooms`/`providers`). **Correction:** the audit's "analytics references non-existent `or_cases.actual_start/end_time`" was a **false positive** — those columns exist on `prod.or_cases` (verified vs migrations); no change. | done  |
| **Net-new**         | huddle action-items API (P2); PI/PDSA write paths, ED write path, simulation read (P4)                                                                                                                                                                                                                                      | 2–4   |

## Appendix C — Risk register

| Risk                                                                   | Sev  | Mitigation                                                                                                           |
| ---------------------------------------------------------------------- | ---- | -------------------------------------------------------------------------------------------------------------------- |
| **KMP commitment decided late** → costly re-architecture               | High | Decide **now** (§5). Floor: separate apps + mandated OpenAPI contract + shared conformance spec                      |
| **Earned-urgency mis-tuned** → alarm fatigue or missed breach (safety) | High | Treat taxonomy as clinical-safety; configurable bands; clinical sign-off gate; track T1 precision + per-shift budget |
| **PHI leak** (notifications/logs/snapshots/SDKs)                       | High | PHI-free push by design + CI payload linter + FLAG_SECURE/privacy view + BAA/PHI-free SDKs; PHI-leak audit gate      |
| **Auth additivity breaks the locked flow**                             | High | Strict additive design; auth regression suite as a P0 gate; never touch protected files                              |
| **Net-new backend gates ED/PI** slips features                         | Med  | Sequence last (P4); ship glanceable signals first (P3); deep-link-to-web fallback                                    |
| **Push reliability** on battery-constrained devices                    | Med  | Push-first + delta-sync; never hold a bg socket; poll-on-resume fallback; real-device testing                        |
| **Apple Critical Alerts / Android FSI permission delays**              | Med  | Apply early in P2; degrade T1 to high-importance until granted                                                       |
| **Web endpoints currently auth-less** = security finding               | Med  | Remediate in P0; BFF is the only mobile surface                                                                      |
| **Reference/OR backend bugs** block P2                                 | Low  | Fix list scheduled before P2 (Appendix B)                                                                            |

## Appendix D — Team & timeline

| Role                     | Count | Focus                                               |
| ------------------------ | ----: | --------------------------------------------------- |
| iOS (Swift/SwiftUI)      |     2 | native UI, widgets/Live Activities, watch           |
| Android (Kotlin/Compose) |     2 | native UI, Glance widgets, Wear                     |
| KMP/shared               |     1 | domain/data/sync/tokens (pairs with both)           |
| Backend (Laravel)        |   1–2 | BFF, auth, push, evaluators, RBAC, broadcasts       |
| Design                   |     1 | token parity, mobile component specs, glanceability |
| QA / security            |     1 | conformance + security/PHI gates                    |
| Clinical-safety advisor  |  part | notification taxonomy + tier sign-off               |
| PM/EM                    |     1 | sequencing, store, rollout                          |

**Timeline:** ~9–12 months to GA; internal pilots from end of P1; P1–P2 overlap once the BFF
pattern is set.

## Appendix E — Program definition of done

- The [parity matrix](#appendix-a--full-feature-parity-matrix) is fully reconciled: every
  GLANCE/ACT/NOTIFY row shipped or explicitly deferred with a tracked backend ticket; every
  WEB row has a working deep link.
- **All seven security release-gate criteria** pass ([06-security-hipaa.md §9](reference/06-security-hipaa.md#9-release-gate-acceptance-criteria)),
  notably: **zero PHI** leaves the boundary (notifications/logs/snapshots/analytics);
  **auth additivity** proven by a green regression suite over the locked flow; **biometric +
  auto-lock + remote-revoke**; **cert pinning** with rotation; **action RBAC** unbeatable by a
  tampered client; **audit trail** on every mutation; **named security + clinical-safety
  sign-off**.
- The [notification taxonomy](#9-earned-urgency-notifications-invariant) meets its
  precision/budget criteria in pilot and has clinical-safety sign-off.
- One design system, three platforms — verified non-divergent by the token CI check.

---

## Document map

This consolidated plan is the front door. Detail lives in:

**Deep-dive planning docs**
[00 Vision/Personas](reference/00-vision-scope-personas.md) ·
[01 Parity Matrix](reference/01-feature-parity-matrix.md) ·
[02 Functionality by Role](reference/02-core-functionality-by-role.md) ·
[03 Architecture](reference/03-architecture.md) ·
[04 Backend & API](reference/04-backend-requirements.md) ·
[05 Notifications](reference/05-notifications-earned-urgency.md) ·
[06 Security/HIPAA](reference/06-security-hipaa.md) ·
[07 Roadmap](reference/07-roadmap-phasing.md)

**Scaffolding (begun)**
[design-tokens/](design-tokens/) (DTCG source + Style Dictionary + samples) ·
[api-contract/hummingbird-bff.v1.yaml](api-contract/hummingbird-bff.v1.yaml) (validated OpenAPI 3.1)

**Evidence — the seven-stream code audit**
[research/](research/) (`01`–`07`: ED, RTDC, Perioperative, Improvement/Ops, Platform,
Supporting domains, Mobile best practices)
