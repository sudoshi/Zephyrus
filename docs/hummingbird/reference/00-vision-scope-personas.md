# 00 — Vision, Scope & Personas

## 1. Vision

> **Hummingbird is the glance-and-act surface of Zephyrus.** It carries the same defensible
> truth as the operations bridge, sized for a hand and a 3-second window, and turns the
> single most important thing into a two-tap action — without ever crying wolf.

The name is deliberate: a hummingbird is small, precise, always aware of its surroundings,
and acts in tiny decisive bursts. That is the interaction model — not a dashboard you study,
but an instrument you flick to, act on, and pocket.

### Design DNA inherited from Zephyrus (non-negotiable)

Hummingbird is bound by the same canon as the web app ([PRODUCT.md](../../../PRODUCT.md),
[DESIGN.md](../../../DESIGN.md)). On mobile these translate to:

- **Rigorous · Composed · Defensible.** Numbers survive scrutiny; provenance travels with
  the metric even on a tile. Never breathless, never cute.
- **The dual test, on mobile.** It must be the app a CMO trusts to glance at house status
  *and* the app a charge nurse trusts to clear a barrier mid-shift. Neither is the
  afterthought — solved by **role-adaptive home screens**, not separate apps.
- **Earned urgency is a safety system.** Color and notifications are rationed. Coral-red and
  a phone buzz are the most expensive signals we have. Alarm fatigue is a clinical-safety
  failure, not a UX nit. → [05-notifications-earned-urgency.md](05-notifications-earned-urgency.md)
- **Status never by color alone.** Every status pairs color with an icon, arrow, or label —
  it must survive color-blindness, a cracked screen, and a sunlit ambulance bay.
- **Dark-default, dual-theme, `tabular-nums`, gold focus.** The night shift is the default
  context. Metrics never reflow as they tick.
- **Replace the EHR, don't reskin it.** Density *with* clarity. We do not ship a 1998
  enterprise toolbar on a phone, and we do not ship a playful consumer app either.

### Anti-references (what Hummingbird must NOT become)

- A shrunk-down desktop dashboard with 9 panels crammed onto a phone.
- A consumer health app (gradients, mascots, gamified streaks, emoji).
- An **alarm-fatigue pager** — the thing staff silence and then miss the one real breach.
- A generic Material/HIG template with no point of view.

---

## 2. Scope

### In scope for the Hummingbird program (v1 → v3)

- **One app per platform**, native, sharing a KMP domain core.
- **Role-aware home** + a unified **"For You"** action queue across all domains.
- **The action-first slices** of every Zephyrus domain that pass the glance-and-act test:
  RTDC (beds, barriers, huddles, census), Perioperative (case/room tracking, safety,
  milestones, transport-to-OR), Ops/AI (approvals & actions), Transport, EVS, Staffing,
  and the read-only Command-Center / Executive Brief.
- **Earned-urgency push notifications** with actionable categories.
- **Biometric-gated, token-based auth** layered additively onto the existing system.
- **Offline-aware** read caching + queued writes with explicit staleness.
- **At-a-glance OS surfaces:** widgets, iOS Live Activities / Dynamic Island, Watch/Wear
  complications for house/unit status (phased).

### Explicitly out of scope for v1 (deep-link to web instead)

- Heavy analytics: block/OR utilization, primetime, turnover, provider/service analytics,
  historical trends, forecasting workbenches.
- Process mining / OCEL root-cause / drag-canvas process layouts (4D navigator).
- Simulation authoring & the full agent-evaluation console.
- Facility blueprint modeling and integration/connector administration.
- Admin (user management, auth-provider config) beyond self-service profile.
- Any net-new clinical documentation (Hummingbird acts on operational state; it is not an EHR).

> **Rule of thumb:** if a task needs more than ~3 visible panels, a keyboard, or sustained
> study, it stays on the web and Hummingbird offers a **deep link** ("Open in Zephyrus").

### Platform/data reality that constrains v1 sequencing

- Build the **three gating capabilities first** (token auth, push, BFF) — nothing ships
  without them. → [04-backend-requirements.md](04-backend-requirements.md)
- **Lead with RTDC** (it has live data + websockets) and **Ops approvals** (reuse endpoints).
- **Transport/EVS** next (clean lifecycles, classic mobile workers).
- **ED and PI** features wait on net-new backend; ship their *glanceable* signals
  (boarding, diversion, surge — already computed) before their *actionable* ones.

---

## 3. Personas — "all levels of the healthcare delivery system"

The brief is explicit: core functionality must serve **all healthcare workers at various
levels**. Zephyrus models its audience as three altitudes (frontline / ops-leaders /
executives) switched by a **workflow preference**, *not* a hard permission
([research 05](../research/05-platform-auth-realtime-design.md)). Hummingbird honors that —
**one app, role-adaptive home** — and extends it down to the frontline mobile worker
(transporter, EVS tech) the web app under-serves.

> **Authorization note:** today only `super-admin`/`admin` is a real gate; workflow is a
> preference any user can switch. Hummingbird ships a **role-aware home derived from
> `workflow_preference` + assignment**, and the backend work adds the proper RBAC the
> mobile context demands (you should only *act* on what's assigned to you).
> → [04-backend-requirements.md](04-backend-requirements.md)

### The persona ladder

| # | Persona | Altitude | Primary device context | What they need from Hummingbird |
|---|---------|----------|------------------------|----------------------------------|
| P1 | **Transporter / Porter** | Frontline | On the move, gloves, one hand | Claim/progress transport jobs; structured handoff; STAT alerts |
| P2 | **EVS Technician** | Frontline | On the move, PPE, isolation rooms | Claim/clean/complete bed-turns; isolation SOP; "next dirty bed" |
| P3 | **Bedside / Charge Nurse** | Frontline unit | Mid-shift, glancing | Unit census & safe-capacity; discharge barriers; bed requests; huddle steps; incoming transports |
| P4 | **OR Circulating / Charge Nurse** | Frontline OR | Between cases | Live room board; case progress; safety-note SLAs; pre-op milestones; "you're up next" |
| P5 | **Nursing Supervisor / Bed Manager** | Ops leader | Roaming the house, command center | House bed-need roll-up; pending bed requests; placement decisions; barriers across units; diversion |
| P6 | **Patient-Flow / Capacity Lead** | Ops leader | Command center, huddles | Capacity vs. demand; huddle facilitation; **approve/assign operational actions**; surge |
| P7 | **Perioperative Manager** | Ops leader | Roaming periop | OR utilization at a glance; delays/cancellations; turnover; staffing gaps |
| P8 | **PI / Quality Lead** | Ops leader | Desk + roaming | PDSA ownership & due items; barrier trends (mostly read on mobile, act on web) |
| P9 | **Executive (CMO/COO/CNO)** | Executive | Between meetings, off-site | House strain index; Executive Brief; the one breach that matters; weekly digest |
| P10 | **Staffing Coordinator** | Ops leader | Roaming, phone-heavy | Open staffing requests; gaps below safe minimum; fill/assign |

Each persona maps to a **default home** and a curated **"For You"** queue. The full per-role
screen set is specified in [02-core-functionality-by-role.md](02-core-functionality-by-role.md).

### Shared core (every persona, every level)

Regardless of role, every worker gets:

1. **Secure, fast entry** — biometric unlock, token session, auto-lock.
2. **A role-aware home** — their altitude's most important truth, glanceable.
3. **One "For You" queue** — a single prioritized, cross-domain list of what needs them.
4. **Rationed, actionable notifications** — act from the lock screen; never spammed.
5. **A directory & house-status glance** — units, beds, capacity (PHI-gated by role).
6. **Profile & preferences** — workflow default, notification tiers, quiet hours, theme.
7. **Deep links to the web** — every "this is desktop work" path opens Zephyrus cleanly.

---

## 4. Success criteria (how we know v1 worked)

| Dimension | Target |
|-----------|--------|
| **Time-to-act** | A bed manager approves a pending bed request, or a transporter claims a STAT job, in **< 10 s** from notification — from a locked phone. |
| **Earned urgency** | Median **push notifications per user per shift ≤ a defined budget**; critical-tier precision (acted-upon ÷ sent) tracked; **zero** "everything buzzes" complaints. |
| **Parity (read)** | Every **GLANCEABLE** web metric in scope is reachable in **≤ 2 taps**. |
| **Parity (act)** | Every **ACTIONABLE** web mutation in scope is doable on mobile with the same backend contract. |
| **Trust** | Provenance/staleness visible on every metric; **no** number on Hummingbird disagrees with the web for the same as-of time. |
| **Safety & compliance** | **Zero** PHI in notifications, logs, or app-switcher snapshots; biometric + auto-lock enforced; passes the [security checklist](06-security-hipaa.md). |
| **Accessibility** | WCAG 2.2 AA (pragmatic): dynamic type, VoiceOver/TalkBack, status-never-by-color, 44pt targets. |
| **Reliability** | Works degraded offline (reads cached, writes queued, staleness shown); no data loss on reconnect. |
