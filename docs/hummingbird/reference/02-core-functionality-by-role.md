# 02 — Core Functionality by Role

This document specifies the **shared core** every healthcare worker gets and the
**role-adaptive** home + journeys for each persona altitude. It satisfies the requirement to
*"create core functionality that is available to all healthcare workers at various levels of
the healthcare delivery system."*

The model is **one app, many homes**. Zephyrus already treats its audience as one product
switched by `workflow_preference` rather than split into separate apps; Hummingbird honors
that and extends it to the frontline mobile worker. A single binary adapts its **home
screen**, its **"For You" queue**, and its **notification defaults** to the signed-in
worker's role and assignment.

---

## 1. The shared core (every worker, every level)

These seven capabilities are present for **all** personas. They are the spine of the app.

### 1.1 Secure entry
- Token login (OAuth2+PKCE / Sanctum), then **biometric unlock** on every cold start and
  after the idle auto-lock window.
- Honors the protected **temp-password / `must_change_password`** flow: a first-login user
  is routed to a forced password change before any operational screen.
- → [06-security-hipaa.md](06-security-hipaa.md)

### 1.2 Role-aware Home
- The worker's **altitude's single most important truth**, glanceable in 3 seconds, dark by
  default, tabular, status-disciplined. Composition differs per persona (§3).
- A **workflow switcher** (same six values as web: superuser / rtdc / perioperative /
  emergency / improvement / transport) lets any user re-pivot the home — a preference, not a
  gate, mirroring the web.

### 1.3 The "For You" queue — *the unifying primitive*
A single, prioritized, **cross-domain** list of what needs *this* worker now. It merges,
de-duplicates, and ranks items from every domain by an **earned-urgency score** (severity ×
assignment × time-pressure). Examples of what lands here, by role:

- Bed manager: *pending bed requests, placement contention, new/aging barriers, capacity breach.*
- Charge nurse: *barriers on my unit, incoming transports, bed-turn completions, huddle step due.*
- Capacity lead: *operational actions awaiting my approval, surge alert, huddle facilitation.*
- Transporter/EVS: *STAT job assigned to me, SLA at-risk, patient-ready.*
- OR nurse: *safety-note SLA, "you're up next," pre-op milestone incomplete.*
- Executive: *house status escalation, the one breach, morning brief ready.*

The queue is the same component everywhere; only its **feed sources and ranking weights**
are role-tuned. Each item is a card with a **primary action inline** (Approve, Claim,
Resolve, Acknowledge) so the common action is **zero extra navigation**.

### 1.4 Rationed, actionable notifications
- Push that mirrors the "For You" queue's top tier. **Actionable categories** let a worker
  Approve / Claim / Acknowledge **from the lock screen** without opening the app.
- Per-tier opt-in, **quiet hours**, and an explicit **per-shift budget** so the app never
  becomes a pager. → [05-notifications-earned-urgency.md](05-notifications-earned-urgency.md)

### 1.5 House / unit status glance
- A read-only capacity glance scoped to the worker's role (a transporter sees far less than
  a bed manager). PHI is gated; counts and statuses are not.
- Available as a **home tile, a widget, and (phased) a Live Activity / complication**.

### 1.6 Directory & search
- The web's ⌘K command palette becomes a **search screen**: units, beds, rooms, providers,
  active requests. Role-scoped; PHI-minimized.

### 1.7 Profile, preferences & deep links
- Default workflow, notification tiers, quiet hours, theme (defaults dark), biometric toggle.
- Every "this is desktop work" path offers **Open in Zephyrus** (deep link) rather than a
  cramped re-implementation.

---

## 2. The "altitude" model

| Altitude | Personas | Home emphasis | Dominant verbs |
|----------|----------|---------------|----------------|
| **Frontline — mobile worker** | Transporter, EVS tech | *My job queue* | claim, progress, complete, hand off |
| **Frontline — unit** | Charge/bedside nurse, OR nurse | *My unit / my cases* | clear barrier, request bed, track case, acknowledge |
| **Ops leader** | Bed manager, capacity lead, periop mgr, staffing coord, PI lead | *The house / my domain* | approve, assign, place, facilitate, resolve |
| **Executive** | CMO/COO/CNO | *Is the hospital OK?* | read, drill once, escalate |

"Calm is the default; urgency is earned" expresses differently per altitude: a transporter's
home is a **task list**; an executive's home is a **single strain index** that stays quiet
until it shouldn't.

---

## 3. Per-persona specifications

Each spec lists the **Home**, the **"For You" feed**, the **primary journeys** (the two-tap
flows), **notifications**, and the **delivery phase**.

### P1 — Transporter / Porter  *(Frontline mobile worker · Phase 1)*
- **Home:** "My Trips" — claimed/active jobs as a vertical timeline; a prominent **Available
  Jobs** section sorted by priority + proximity; a STAT banner when one exists.
- **For You:** STAT/at-risk jobs assigned or offered to me; patient-ready/not-ready flips.
- **Journeys:**
  1. *Claim → run a trip:* tap a job → **Claim** → step through `dispatched → arrived →
     picked_up → en_route → arrived → handoff` with one big primary button per state and a
     map/anchor to origin/destination.
  2. *Structured handoff:* at destination, capture `handoff_to`, summary, documents,
     `outstanding_risks[]` → **Complete**.
- **Notifications:** "STAT transport assigned: {from}→{to}" (actionable: Claim); "Patient not
  ready"; "SLA at risk — {needed_at} passed."
- **Backend:** all LIVE (`/api/transport/*`). One-hand, large targets, glove-friendly.

### P2 — EVS Technician  *(Frontline mobile worker · Phase 1)*
- **Home:** "My Bed-Turns" + **Next Dirty Bed** queue, each badged with `turn_type`
  (terminal/isolation/spill) and an **isolation** flag that surfaces the PPE/SOP prompt.
- **For You:** isolation turns, STAT turns, SLA at-risk.
- **Journeys:** *Claim → clean → done:* **Claim** (→assigned) → **Start** (→in_progress,
  stamps `started_at`, shows SOP/PPE for isolation) → **Complete** (→completed, stamps
  `completed_at`, completion payload). Completion **notifies the bed manager** (unblocks
  placement).
- **Notifications:** "Isolation bed-turn assigned: {bed}"; "Bed-turn SLA at risk."
- **Backend:** all LIVE (`/api/evs/*`).

### P3 — Bedside / Charge Nurse  *(Frontline unit · Phase 1–2)*
- **Home:** "My Unit" — live census with **acuity-adjusted safe capacity** (WS-driven),
  available beds, pending admits/discharges; a barriers strip; incoming transports/bed-turns.
- **For You:** unresolved/aging **barriers on my unit**, incoming transports, bed-turn
  completions (bed ready), huddle step due, capacity tip into deficit.
- **Journeys:**
  1. *Clear a discharge barrier:* tap barrier → **Resolve** (or reassign/log new).
  2. *Request a bed:* **New Bed Request** (patient ref, to-unit, priority) → submit.
  3. *Run my huddle steps (P2):* enter expected discharges/demand → see computed bed-need
     (WS-synced to the house roll-up).
- **Notifications:** "Bed {id} ready on {unit}"; "Barrier aging > {n}h"; "Unit {u} now in
  bed deficit."
- **Backend:** LIVE (`/api/rtdc/*`); huddle action-items are `NEW` (P2).

### P4 — OR Circulating / Charge Nurse  *(Frontline OR · Phase 2)*
- **Home:** **Live Room Board** (Available/In-Progress/Turnover derived from wheels clock) +
  "My Cases Today"; each case shows progress % and time-remaining.
- **For You:** safety-note **SLA breaches** (Crit 15m/High 30m/…), "you're up next,"
  **pre-op milestone incomplete** before wheels-in, transport-to-OR ready/overdue.
- **Journeys:** advance case status; create/acknowledge a **safety note**; acknowledge a
  **pre-op milestone** (H&P/Consent/Labs); mark **case transport** ready/complete.
- **Notifications:** "Safety note overdue: {case}"; "OR-{n} ready (~15 min)"; "Case {x}
  delayed."
- **Backend:** LIVE (`/api/cases/*`) after the three store/column fixes (see parity matrix B).

### P5 — Nursing Supervisor / Bed Manager  *(Ops leader · Phase 1)*
- **Home:** **House bed-need roll-up** (net/deficit per unit + total, WS-driven) + pending
  bed requests + barriers across units + diversion banner (P3).
- **For You:** **pending bed requests** to action, placement **contention**, new/aging
  barriers house-wide, capacity breach, **EVS bed-turn completed** (placeable now).
- **Journeys:**
  1. *Place a bed:* open request → review **transparent placement score & rationale** →
     **Accept / edit / reject**.
  2. *Triage barriers across units;* assign/resolve.
- **Notifications:** "New pending bed request: {to-unit}"; "Available beds → 0 on {unit}";
  "Placement unplaced > {n} min."
- **Backend:** LIVE (`/api/rtdc/*`).

### P6 — Patient-Flow / Capacity Lead  *(Ops leader · Phase 2)*
- **Home:** **Capacity vs. demand** + **house strain/surge index (0–4)** + the **Ops
  approvals inbox** (pending/active/assigned/overdue tiles) + 24h forecast.
- **For You:** **operational actions awaiting my approval** ★, surge alert, huddle to
  facilitate, data-feed-stale (qualified metrics).
- **Journeys:**
  1. *Approve on the go:* notification or inbox → review recommendation (title, risk,
     rationale) → **Approve / Reject** (with reason) → optionally **Assign**.
  2. *Facilitate the huddle;* run "Capacity Commander" for huddle-ready next actions.
- **Notifications:** "Recommendation awaiting approval: {title} ({risk})" (actionable:
  Approve/Reject); "House status escalated to {level}"; "Action expiring in {t}."
- **Backend:** LIVE (`/api/ops/*`, `/api/command-center/*`) — **reuses endpoints verbatim**.

### P7 — Perioperative Manager  *(Ops leader · Phase 2–3)*
- **Home:** OR day at a glance — rooms running, on-time starts, delays/cancellations, a
  single utilization tile; turnover outliers.
- **For You:** first-case late starts, cancellations, turnover breaches, OR staffing gaps.
- **Journeys:** drill a delayed case; acknowledge; **deep-link to web** for full block/OR
  utilization analytics.
- **Notifications:** "First-case late start: OR-{n}"; "Case cancelled: {x}."
- **Backend:** LIVE (cases/rooms) + WEB (deep analytics).

### P8 — PI / Quality Lead  *(Ops leader · Phase 4)*
- **Home:** **My PDSA cycles** (stage, owner, due) + barrier/operational-event trend tiles.
- **For You:** PDSA stage due, barrier assigned to me, intervention metric moved.
- **Journeys (P4, needs backend):** advance a PDSA stage; assign an owner; acknowledge a
  barrier. Bottleneck/root-cause/process-mining is **WEB**.
- **Notifications:** "PDSA '{title}' — {stage} due"; "Barrier assigned to you."
- **Backend:** mostly **NEW** (PI layer is stub today).

### P9 — Executive (CMO / COO / CNO)  *(Executive · Phase 2)*
- **Home:** **one screen** — house **strain index (0–4)**, 4–6 hero KPIs (tabular, with
  trust badges + arrows), and the **Executive Brief** (server-composed
  situation/plan/impact/confidence). Quiet by design; loud only on escalation.
- **For You:** house-status escalation, the single most material breach, "morning brief
  ready."
- **Journeys:** read; **drill once** into a KPI; **deep-link to web** for the Monday review.
- **Notifications (sparse, high-bar):** "House status escalated to {level}"; "Your morning
  operations brief is ready"; a single critical-capacity alert.
- **Backend:** LIVE (`/api/command-center/*`).

### P10 — Staffing Coordinator  *(Ops leader · Phase 3)*
- **Home:** **Open staffing requests** + units **below minimum-safe** / critical-gap.
- **For You:** new gap below safe minimum, unfilled/escalated requests.
- **Journeys:** create / source / assign / fill a staffing request; mark filled.
- **Notifications:** "Unit {u} below minimum-safe"; "Staffing request unfilled — escalated."
- **Backend:** LIVE (`/api/staffing/*`).

---

## 4. Role × capability matrix (who gets what)

`●` primary · `○` available · `–` not surfaced (deep-link if needed)

| Capability | Transp | EVS | Charge RN | OR RN | Bed Mgr | Cap Lead | Periop Mgr | PI Lead | Exec | Staff Coord |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| For-You queue | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| House/unit status glance | ○ | ○ | ● | ○ | ● | ● | ○ | ○ | ● | ○ |
| Transport jobs | ● | – | ○ | – | ○ | ○ | – | – | – | – |
| EVS bed-turns | – | ● | ○ | – | ● | ○ | – | – | – | – |
| Beds & barriers (RTDC) | – | – | ● | – | ● | ● | – | ○ | ○ | – |
| Huddles | – | – | ● | – | ● | ● | ○ | ○ | – | ○ |
| OR cases & rooms | – | – | – | ● | – | ○ | ● | – | ○ | – |
| Ops approvals/actions | – | – | – | – | ○ | ● | ○ | ○ | ○ | – |
| Command Center / Exec Brief | – | – | ○ | – | ● | ● | ○ | ○ | ● | ○ |
| ED signals (boarding/diversion/surge) | – | – | ○ | – | ● | ● | – | ○ | ● | ○ |
| Staffing | – | – | ○ | ○ | ○ | ○ | ○ | – | ○ | ● |
| PDSA / PI | – | – | – | – | – | ○ | ○ | ● | ○ | – |

The matrix is the **default surfacing**, not a hard ACL. The backend RBAC work
([04](04-backend-requirements.md)) governs what each role may **act on** (e.g., only the
assigned transporter progresses a trip; only an authorized approver decides an action),
while *glanceable* house status is broadly readable (PHI-gated), matching the web's
"everyone reads the same truth at different altitudes" philosophy.

---

## 5. Why this satisfies "all workers at various levels"

- **Frontline mobile workers** (transporter, EVS) — *under-served by the web today* — get a
  first-class, claim-and-go experience. This is the clearest mobile win and ships first.
- **Frontline unit staff** (charge/OR nurses) get the glance + the handful of actions they'd
  actually take mid-shift, not a shrunk dashboard.
- **Ops leaders** get the house roll-up and, crucially, **approvals/placement/assignment on
  the go** — the decisions that today require them to be at a workstation.
- **Executives** get a single quiet strain index and the brief — situational awareness
  between meetings without the Monday-review density.

One app spans the porter and the CMO because the **primitives are shared** (role-aware home,
one For-You queue, earned-urgency notifications) and only the **feed and ranking** change.
