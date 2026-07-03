# Hummingbird — Mobile Companion for Zephyrus

> **Status:** Planning — v0.1 (2026-06-26)
> **Scope:** Native mobile companion to the Zephyrus hospital-operations command center.
> **Platforms:** Android (Kotlin / Jetpack Compose) · iOS (Swift / SwiftUI), with a shared
> Kotlin Multiplatform (KMP) domain layer.

Hummingbird puts the right number and the one action that needs taking _now_ into the
pocket of every healthcare worker — from the transporter moving a patient, to the charge
nurse working a unit, to the CMO glancing at house status between meetings. It is the
**glance-and-act** surface of Zephyrus, not a port of the desktop dashboards.

> ## 👉 Start here: [**IMPLEMENTATION-PLAN.md**](IMPLEMENTATION-PLAN.md)
>
> The single, consolidated, phased implementation plan — the authoritative front door. It
> states the cross-cutting invariants once, then sequences the work as six self-contained
> phases (P0–P5), with a full parity matrix and reference appendices. The numbered docs
> below ([00–07](#document-map)) are deep-dive references; [research/](research/) is the
> evidence.
>
> ## Direction overlay: [**ALTITUDE-PERSONA-OPERATING-PLAN.md**](ALTITUDE-PERSONA-OPERATING-PLAN.md)
>
> The Zephyrus 2.0 alignment plan for Hummingbird: persona-specific Altitude descent,
> patient/encounter drill, cross-persona action relay, and Eddy awareness of the complete
> house care team decision trail.

---

## Why "companion," not "port"

Zephyrus is a dense, instrument-rich **operations bridge** built for wall displays and
shared workstations — line-by-line analysis, multi-panel layouts, drag-canvas process
mining. Most of that does **not** belong on a 6-inch screen under time pressure. The web
app answers _"what is happening across the hospital, and why?"_ Hummingbird answers a
narrower, sharper question for a worker mid-shift:

> **"What needs _me_, right now — and can I do it in two taps?"**

Every feature in this plan was triaged against that question. We classify each web
capability as one of:

| Flag             | Meaning                                 | Mobile treatment                         |
| ---------------- | --------------------------------------- | ---------------------------------------- |
| **GLANCEABLE**   | Read at a glance; situational awareness | Home tiles, widgets, Live Activities     |
| **ACTIONABLE**   | A worker would _act_ on it from a phone | First-class flows + notification actions |
| **NOTIFY**       | A discrete event worth a push           | Earned-urgency notification taxonomy     |
| **DESKTOP-ONLY** | Too analytical/dense for phone          | Deep-link out to web; not rebuilt        |

---

## The strategic picture (what the research found)

A seven-stream deep audit of the Zephyrus codebase (95 models, ~106 API endpoints, four
workflows + supporting domains) and a grounded best-practices study produced five findings
that shape this entire plan. Full detail in [research/](research/).

1. **RTDC is the crown jewel for mobile.** It is the only subsystem with a
   production-grade, **event-sourced engine** and **live Laravel Reverb websockets**
   (`CensusUpdated`, `HuddleUpdated`, `BedMeetingUpdated`) on public, **PHI-free** channels.
   Bed requests, discharge barriers, huddle workflows, and capacity alerts are the
   highest-value mobile features in the product. → [research/02-rtdc.md](research/02-rtdc.md)

2. **Perioperative and Ops/AI are "ready data, no mobile surface."** Both have complete
   data models and REST contracts but largely mock or desktop-only UIs. The **human-in-the-
   loop approval workflow** (approve/assign/start/complete an operational action) is the #1
   greenfield opportunity — Hummingbird can reuse the existing `/api/ops/*` endpoints
   **verbatim** to deliver "approvals on the go." OR case tracking, room-status boards, and
   safety-note SLAs are strong mobile targets via existing endpoints.
   → [research/03-perioperative.md](research/03-perioperative.md),
   [research/04-improvement-and-ops.md](research/04-improvement-and-ops.md)

3. **Transport and EVS are textbook mobile-worker workflows.** A transporter or EVS tech
   _is_ a mobile user — claim → do → done loops with structured handoffs already modeled as
   append-only event logs (17-status transport lifecycle, 5-status EVS bed-turn).
   → [research/06-supporting-domains-and-api.md](research/06-supporting-domains-and-api.md)

4. **ED and Process Improvement are mostly mock today.** The ED-branded UI is ~90%
   placeholder and has **zero** ED-specific endpoints or mutations; real ED signal
   (boarding, diversion, LWBS, surge) lives in Command-Center/Analytics/Patient-Flow APIs.
   The PI layer (PDSA, bottlenecks) returns stub data. Mobile features here depend on
   **net-new backend** and are sequenced later. → [research/01-emergency-department.md](research/01-emergency-department.md)

5. **Three platform gaps gate everything and must be built first:**
    - **No mobile auth.** Auth is session-cookie only; **Sanctum is installed but dormant**
      (`User` lacks `HasApiTokens`, zero `auth:sanctum` routes). OIDC/Authentik exists but
      also lands a web session. Mobile needs token auth (OAuth2 + PKCE / Sanctum PATs).
    - **No push infrastructure.** There is no APNs/FCM, no device-token registry, no
      notification service. The only "real-time" is Reverb websockets (foreground only) and
      one demo SSE stream. Push is **net-new**.
    - **Inconsistent API auth & shape.** Several operational endpoints (`cases`, `blocks`,
      `rooms`, `providers`, analytics, `improvement/*`) carry **no `auth` middleware** at
      all, and there is no uniform response envelope. A mobile **BFF** with consistent auth,
      shaping, and PHI-minimization is required.

    → [research/05-platform-auth-realtime-design.md](research/05-platform-auth-realtime-design.md),
    [research/07-mobile-best-practices.md](research/07-mobile-best-practices.md)

---

## The headline architecture decisions

| Decision          | Choice                                                                                                 | Rationale                                                                          |
| ----------------- | ------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------- |
| **UI strategy**   | Native per platform — Compose + SwiftUI                                                                | Glanceability/feel matter; native widgets, Live Activities, Critical Alerts        |
| **Shared code**   | **KMP domain + data layer** (status rules, sync, auth, models), bridged to Swift via **SKIE**          | One source of truth for clinical logic so it can't drift between platforms         |
| **Backend auth**  | Add **Laravel Sanctum tokens** (and OIDC+PKCE path) — _additive_, never touching the locked auth rules | The temp-password / `must_change_password` flow is protected; we add, never modify |
| **Real-time**     | **Push-first**, websocket-when-foregrounded (Reverb), poll as fallback                                 | Battery + correctness; never hold a background socket                              |
| **API access**    | A versioned **mobile BFF** (`/api/mobile/v1/*`) that aggregates, shapes, auth-gates, and PHI-minimizes | The web `/api` is chatty, inconsistently authed, and PHI-rich                      |
| **Notifications** | A server-side **four-tier "earned-urgency" router**                                                    | The product's core design principle — alarm fatigue is a clinical-safety failure   |
| **Design parity** | **One token source** (DTCG JSON + Style Dictionary) → Compose + SwiftUI + web CSS                      | "One design system, three platforms," zero drift                                   |
| **PHI on mobile** | Generic push copy, `FLAG_SECURE`/privacy-screen, biometric lock, no PHI in logs                        | Cheapest, highest-impact HIPAA wins                                                |

---

## Document map

**▶ The plan (read this)**

- [**IMPLEMENTATION-PLAN.md**](IMPLEMENTATION-PLAN.md) — the single consolidated, phased plan. Everything below is supporting detail it draws on.
- [**ALTITUDE-PERSONA-OPERATING-PLAN.md**](ALTITUDE-PERSONA-OPERATING-PLAN.md) — the Zephyrus 2.0 direction overlay: Altitude per persona, patient/encounter drill, cross-persona relay, Eddy awareness.
- [**ADR-2026-07-01-altitude-patient-lens.md**](ADR-2026-07-01-altitude-patient-lens.md) — records `A2P` as a patient/encounter drill leaf, not a fifth altitude.
- [**PLATFORM-RECONCILIATION-TODO.md**](PLATFORM-RECONCILIATION-TODO.md) - the Android/iOS parity execution checklist after the Altitude 2.0 and current-code reconciliation review.

**Deep-dive references** (granular detail behind the consolidated plan)

1. [00 — Vision, Scope & Personas](reference/00-vision-scope-personas.md) — who, what, and the boundaries of v1.
2. [01 — Feature Parity Matrix](reference/01-feature-parity-matrix.md) — **every** web data element & function mapped to a mobile treatment. _(the "maps to all data elements" requirement)_
3. [02 — Core Functionality by Role](reference/02-core-functionality-by-role.md) — the shared core + role-specific home screens and journeys. _(the "available to all healthcare workers at various levels" requirement)_
4. [03 — Technical Architecture](reference/03-architecture.md) — KMP + native, offline-aware sync, real-time, modules, project structure.
5. [04 — Backend Requirements & Mobile API Contract](reference/04-backend-requirements.md) — the BFF, token auth, push service, broadcast triggers, RBAC.
6. [05 — Notifications & Earned Urgency](reference/05-notifications-earned-urgency.md) — the tiered notification taxonomy and escalation contract.
7. [06 — Security, HIPAA & PHI](reference/06-security-hipaa.md) — the compliance checklist and acceptance criteria.
8. [07 — Implementation Roadmap & Phasing](reference/07-roadmap-phasing.md) — phases, milestones, team, estimates, risks.

**Scaffolding (begun)**

- [design-tokens/](design-tokens/) — the cross-platform token source (DTCG JSON) + Style Dictionary config + sample Compose/SwiftUI outputs.
- [api-contract/](api-contract/) — the mobile BFF OpenAPI sketch (`hummingbird-bff.v1.yaml`).
- [role-catalog.v1.json](role-catalog.v1.json) — interim shared role, home-kind, feature-route, status, and urgency vocabulary contract used to verify backend/iOS/Android parity.

**Backend — Phase 0 (built, pending verification)**

- [PHASE-0-BACKEND.md](PHASE-0-BACKEND.md) — additive Sanctum token auth, the assignment model, the push-device registry + `PushNotifier` seam, and the first BFF slice (`/api/auth/*`, `/api/mobile/v1/*`). Includes the verification/acceptance steps to run in a PHP environment.

**Research (the audit that grounds all of the above)**

- [research/01-emergency-department.md](research/01-emergency-department.md)
- [research/02-rtdc.md](research/02-rtdc.md)
- [research/03-perioperative.md](research/03-perioperative.md)
- [research/04-improvement-and-ops.md](research/04-improvement-and-ops.md)
- [research/05-platform-auth-realtime-design.md](research/05-platform-auth-realtime-design.md)
- [research/06-supporting-domains-and-api.md](research/06-supporting-domains-and-api.md)
- [research/07-mobile-best-practices.md](research/07-mobile-best-practices.md)

---

## The one-screen summary of v1

A worker opens Hummingbird, authenticates with biometrics, and lands on a **role-aware
home** that shows their unit/house status at a glance and a single prioritized **"For You"**
list — bed requests to action, barriers to clear, approvals awaiting a decision, transport
or EVS jobs to claim, cases to track. Discrete events arrive as **rationed, actionable
push notifications** they can act on without opening the app. Everything is **dark-default,
tabular, and color-disciplined** — the same defensible instrument as the bridge, sized for
a hand. Deep analysis stays on the web, one tap away.
