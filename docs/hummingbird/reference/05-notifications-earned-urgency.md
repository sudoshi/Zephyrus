# 05 — Notifications & Earned Urgency

> **A notification is the most expensive signal Hummingbird can send.** It interrupts a
> clinician under time pressure. The product's core principle — *"earned urgency; if
> everything alarms, nothing does"* — makes the notification system a **clinical-safety
> component**, not a growth feature. A mis-tuned taxonomy causes alarm fatigue (staff silence
> the app and miss the one real breach) — a failure mode the FDA explicitly warns about for
> clinical Focus/DND. This document is therefore a contract, with acceptance criteria.

---

## 1. Principles

1. **Severity is earned per event, never ambient.** Most operational state changes are
   **GLANCE** (they update a tile/widget silently). Only a minority are **NOTIFY**.
2. **Route to the person who can act.** A page goes to the worker **assigned** to or
   **responsible** for the thing — not broadcast to a role. (Requires the assignment model
   in [04 §3](04-backend-requirements.md).)
3. **The action is in the notification.** If the common response is one tap (Approve, Claim,
   Acknowledge), it happens **on the lock screen** — zero app-open.
4. **Respect the human.** Per-tier opt-in, **quiet hours**, **per-shift budgets**, and
   **de-duplication/coalescing** are mandatory, not optional.
5. **No PHI, ever.** Notification copy is **generic** ("New STAT transport assigned");
   patient/clinical detail appears only **inside** the app after biometric unlock.
   → [06-security-hipaa.md](06-security-hipaa.md)
6. **Escalate, don't repeat.** If an actionable, time-critical page isn't acted on, it
   **escalates** (re-deliver → escalate to the next responsible party → fall back to the web
   command center) rather than buzzing the same phone repeatedly.

---

## 2. The four tiers

| Tier | Name | Meaning | iOS | Android | Quiet hours | Default |
|------|------|---------|-----|---------|-------------|---------|
| **T1** | **Critical breach** | A genuine threshold breach or safety event needing action *now* | **Critical Alert** (entitlement; bypasses mute/DND) | **Full-screen intent** / high-importance channel | **Overrides** (with per-user cap) | Opt-out discouraged |
| **T2** | **Actionable** | Something assigned to you to act on soon | Time-Sensitive interruption + action buttons | High-importance channel + actions | Deferred unless assigned-to-me STAT | On |
| **T3** | **Awareness** | Worth knowing; not urgent | Passive (Notification Center) | Default/low channel | Silenced | On (coalesced) |
| **T4** | **Digest** | Periodic summary | Scheduled, passive | Scheduled, low | Silenced | On (1–2/day) |

**Mapping to OS capabilities (and their gates):**
- iOS **Critical Alerts** require a special Apple entitlement (separate approval, justify
  clinical use) — **only T1** uses it. Live Activities carry T1/T2 *status*, not extra buzzes.
- Android **full-screen intents** require the `USE_FULL_SCREEN_INTENT` permission (enforced
  since Android 14 / Jan 2025 for non-calendar/alarm apps) — **only T1**. Channels let users
  tune T2–T4 without us losing T1.

---

## 3. Event → tier taxonomy

Every NOTIFY row in the parity matrix lands here with a tier, an audience, and an action.
**Default to the lowest tier that's defensible**; promotion to T1 must clear a real-breach bar.

### RTDC / capacity
| Event | Tier | Audience (assigned/role) | Lock-screen action |
|-------|------|--------------------------|--------------------|
| Unit safe-capacity exhausted / available beds → 0 | **T1** | charge RN (unit), bed manager | View |
| House bed deficit (`bed_need > 0`) crosses band | T2 | bed manager, capacity lead | View |
| New **pending bed request** | T2 | bed manager (covering unit) | **Place** |
| Bed-request unplaced > N min | T2→T1 (escalates) | bed manager → supervisor | Place |
| Placement contention (409/422) | T2 | bed manager | Resolve |
| New/aging unresolved **barrier** | T3→T2 (by age) | barrier owner / charge RN | **Resolve** |
| EVS bed-turn completed (bed placeable) | T3 | bed manager | View |
| Diversion started / ended *(P3)* | **T1** / T3 | house supervisor, exec | View |

### Ops / approvals
| Event | Tier | Audience | Action |
|-------|------|----------|--------|
| **Recommendation awaiting your approval** | T2 | designated approver | **Approve / Reject** |
| Operational action **expiring** (`expires_at` − lead) | T2→T1 | approver/owner | Approve |
| Action overdue / assigned to you | T2 | assignee | Start / Complete |
| House status **escalated** to {level} | **T1** | capacity lead, exec | View |
| Critical capacity risk (N ED boarders) | **T1** | capacity lead | View |
| Data feed stale → metrics qualified | T3 | ops, affected leads | View |

### Perioperative
| Event | Tier | Audience | Action |
|-------|------|----------|--------|
| Safety-note **SLA breach** (Crit 15m/High 30m/…) | **T1**(Crit)/T2 | OR charge RN, case team | **Acknowledge** |
| Pre-op **milestone incomplete** before wheels-in | T2 | OR nurse | Acknowledge |
| Case **delayed / running long** (`variance>0`) | T3→T2 | OR charge, periop mgr | View |
| "You're up next" / schedule change | T2 | next case team | View |
| Room ready (or_out) | T3 | next team, transport | View |
| First-case **late start** | T2 | periop mgr | View |
| Case **cancelled** | T2 | periop mgr, scheduling | View |
| Transport-to-OR ready / overdue | T2 | transporter, OR | Claim/View |

### Transport / EVS / Staffing (frontline workers)
| Event | Tier | Audience | Action |
|-------|------|----------|--------|
| **STAT transport assigned to me** | **T1** | the assigned transporter | **Claim / Start** |
| Routine transport assigned / offered | T2 | transporter | Claim |
| Patient ready / **not ready** | T2 | assigned transporter | View |
| Transport SLA at risk (`needed_at`) | T2→T1 | transporter → dispatcher | View |
| Handoff with **outstanding risks** | T2 | receiving party | View |
| **Isolation** bed-turn assigned | **T1** | assigned EVS tech | **Claim** |
| Routine bed-turn assigned / SLA at risk | T2 | EVS tech | Claim |
| Unit **below minimum-safe** staffing | **T1** | staffing coord, charge RN | View |
| Staffing request unfilled / escalated | T2 | staffing coord | Source |

### ED *(P3+)*
| Event | Tier | Audience | Action |
|-------|------|----------|--------|
| ED **diversion** active | **T1** | house supervisor, exec | View |
| High-acuity **ESI 1–2 LWBS** (safety breach) | **T1** | ED charge, supervisor | View |
| ED **boarding ≥ 6** (band crossing) | T2 | capacity lead, ED charge | View |
| Boarder dwell > 4h since admit decision | T2 | bed manager | View |
| Surge probability critical | T2 | capacity lead | View |

### Executive & digests
| Event | Tier | Audience | Action |
|-------|------|----------|--------|
| Morning **Executive Brief** ready | T4 | exec | Read |
| End-of-shift unit/house digest | T4 | leads | Read |
| Prediction-reliability daily digest | T4 | capacity lead | Read |

---

## 4. The routing pipeline (server-side)

```
event (broadcast / *_events log / evaluator)
   │
   ▼
[1] CLASSIFY   → tier + candidate audience (role + assignment) + action category
[2] ROUTE      → resolve to specific user(s) via assignment model & user_unit;
                 drop if no actionable recipient (don't page a role blindly)
[3] FILTER     → per-user tier opt-in · quiet hours · per-shift budget · dedupe/coalesce
[4] RENDER     → PHI-free copy + action buttons + deep link (collapse-key/thread-id)
[5] DELIVER    → APNs/FCM (silent for GLANCE delta-sync; visible for NOTIFY)
[6] ESCALATE   → if T1/T2 actionable & unacked within SLA → re-deliver → next responsible →
                 surface on web command center; log the whole chain (audit)
```

- **De-duplication/coalescing:** identical or rapidly-repeating events collapse to one
  (e.g., census ticking) using a stable `collapse_key`/`thread_id`. Boards update **silently**
  via Live Activity / widget, not via a buzz per tick.
- **Per-shift budget:** a soft cap on T2/T3 per user per shift; overflow demotes to the
  in-app For-You queue. T1 is never budget-capped but **is** rate-limited against duplicates.
- **Quiet hours & on-call:** user-set; T1 overrides (with a per-user override cap to prevent
  abuse). Integrates with each platform's Focus/DND honestly (we don't fake-bypass).

---

## 5. Client behavior

- **Categories/channels** registered per tier so users tune T2–T4 in OS settings without
  losing T1. Actionable categories carry **Approve/Reject**, **Claim/Start**,
  **Acknowledge** buttons handled by a notification-service extension (iOS) / broadcast
  receiver + WorkManager (Android) that calls the BFF directly — **no app open required**.
- **Critical Alerts (iOS) / full-screen intent (Android)** wired for **T1 only**, behind the
  required entitlements/permissions.
- **Live Activities / Dynamic Island** show evolving *status* (active trip progress, room
  board, house strain) without extra notifications — the at-a-glance channel that *reduces*
  the need to page.
- **Tapping** any notification deep-links to the exact item (and unlocks via biometric for
  any detail beyond the generic copy).

---

## 6. Acceptance criteria (this system is "done" only if)

- **Zero PHI** in any notification payload (copy, title, or data) — verified by an automated
  payload linter in CI.
- **T1 precision** (acted-upon ÷ delivered) tracked and reviewed; **T2/T3 volume** stays
  within the per-shift budget for ≥ 95% of users.
- **Every T1/T2 actionable** notification supports its action from the **lock screen**.
- **Escalation chains** are logged and auditable; no T1 actionable event is ever "fire and
  forget."
- **User controls** (per-tier opt-in, quiet hours) are honored exactly; the only override is
  documented (T1, capped).
- A **clinical-safety review** signs off the taxonomy before GA, and the tier assignments are
  **configurable** (so a site can tune bands without an app release).
