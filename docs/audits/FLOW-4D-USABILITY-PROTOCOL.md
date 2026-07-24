# Patient Flow 4D Navigator — Formative Usability Test Protocol

**Version:** 1.0 (2026-07-19) · **Parent:** [FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md](../plans/FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md) §H3
**Type:** formative, scenario-based, think-aloud, with situation-awareness probes
**Surface under test:** `/rtdc/patient-flow-navigator` on the prod demo environment (post-H1 deploy)

Every task below operationalizes an acceptance claim the advancement plan already makes. Thresholds are declared up front: **a miss produces a logged design action, not a debate.**

---

## 1. Participants

| Persona | Lens | n | Notes |
|---|---|---|---|
| Charge nurse | unit (detail dots) | 3–5 | Primary frontline user |
| House supervisor / RTDC | house (full dots) | 3–5 | Primary command-center user |
| Executive | aggregate | 2–3 | Role-switcher path; tasks 1, 2, 7 only |

- No prior exposure to the navigator (or ≥3 months since).
- Do **not** screen for color vision — a real-world mix is the point. Note (self-report, optional) any CVD.
- ≥1 participant per clinical persona completes the keyboard-only variant of task 6.

## 2. Setup

- Prod demo environment mid-cycle (urgency census from H4 confirms ordinary load, not a quiet or saturated extreme).
- Two stations: the wall display (standing, ~2–3 m viewing) and a desktop (seated, mouse+keyboard). Tasks 1–2 run at the wall; the rest at the desktop.
- Screen + audio recording; think-aloud instructed ("say what you're looking for and what you expect before you act").
- Moderator script: no leading; the standard probe is *"what would you try?"* then *"what did you expect to happen?"*
- Session target ≤ 30 min. Pilot once (self-run) to confirm timing before recruiting.

## 3. Tasks

Order: task 3 always runs before task 4 (scope control must be discovered naturally). Tasks 5–6 randomized. Record time, success (unaided / prompted / failed), errors, and quotes per task.

### T1 — Element naming (plan claim: "name any element within 5 seconds")
Moderator points (laser/cursor) at 10 elements in this order: patient token, trail, ok disk, **delayed disk + triangle**, timer pip, ghost sphere, forecast pillar, sky diamond, coral diamond, round-stop ring. Participant names each in their own words (semantic match counts; "someone still to be seen" = round stop).
**Pass: ≥8/10 correct, median latency <5 s.** The Key legend may be opened — record whether they find it unprompted.

### T2 — Time recovery (claim: "return to now in one click")
"Take the timeline back to about six hours ago and tell me what you see. … Now bring it back to the present."
**Pass: return to now is ONE action (Now button or N), 100% of participants.** Record whether anyone drag-hunts the slider.

### T3 — Barriers layer task (wrong-toggle discrimination, part A)
"Those floating diamonds are logged operational barriers. Hide them for a moment, then bring them back."
**Pass: uses the Barriers layer switch; 0 uses of the Census scope.**

### T4 — Delayed-only task (wrong-toggle discrimination, part B — the critical task)
"Now show me only the locations that are actually running late right now."
**Pass: uses Census → Delayed; 0 uses of the Barriers layer switch after first exposure to both controls. Any wrong-toggle activation is a P1 finding** (this error mode is what the Phase 0 redesign exists to prevent).
Follow-up probe: *"Is what you're seeing verified barriers or elapsed-time signals?"* (tests the disclaimer's effectiveness).

### T5 — Delayed-cluster triage
"Find the worst delayed cluster in the house and tell me WHY it's delayed — what's the evidence?"
Expected path: chip count → Focus → disk/triangle → inspector timer evidence (reason, owner, target).
**Pass: reaches named timer evidence unaided; can state at least one reason + owner.**

### T6 — Rounds round-trip (Phase 3 claim)
Start on the Rounds board: "Take this patient's stop into the 3D view, walk to the next two stops, then get back to this patient on the board."
**Pass: completes Locate-in-4D → Next ×2 → Open-in-Rounds-board unaided. Moderator verifies no patient identity is ever visible in the scene layer.**
**Keyboard-only variant (≥1 per clinical persona):** find a patient via Find, select from the match list, `F` to fly, Escape to clear. **Pass: completes without touching the mouse.**

### T7 — Floor + orientation
"Get me floor N, framed. Where are you now?" (camera readout should be usable as the answer).
**Pass: floor rail or dropdown in ≤2 actions; participant can state floor/unit from the readout.**

## 4. Situation-awareness probes (SAGAT-lite)

Three display freezes (moderator blanks the screen), placed after T2, T5, and T6:

1. **"How many barriers have been open more than 24 hours right now?"** (ground truth from the barrier diamonds: amber + coral count)
2. **"Which unit is under the most delay pressure?"** (ground truth from the urgency census / occupancy rollup)
3. **"Is the scene currently showing now, the past, or a projection?"** — the mode-confusion probe. **Any wrong answer here is a P1 finding** (an operator acting on a projection as if it were now is the worst failure this display can cause).

**Pass: probes 1–2 within ±1 / correct unit for ≥70% of participants; probe 3 correct for 100%.**

## 5. Measures & instruments

- Per task: success level (unaided=2 / prompted=1 / failed=0), time-on-task, error count (wrong-toggle errors tallied separately), confidence (1–5 self-report after each task).
- Post-session: **SUS** (10-item). Interpretive anchors: <68 investigate broadly; 68–80 acceptable; >80 strong.
- SA probe accuracy per §4.
- Qualitative: verbatim quotes tagged to the doctrine they touch (earned urgency, wrong-toggle, mode confusion, discoverability).

## 6. Analysis & disposition

- Findings table: description × severity (P0 blocks safe use / P1 misleads or fails a declared threshold / P2 friction / P3 polish) × frequency.
- Every threshold miss maps to exactly one of: **design action** (ticketed, branch `feature/flow4d-hfe-usability-fixes`), **accepted risk** (written rationale in the closure plan), or **retest item** (ambiguous, carries to a follow-up session).
- Results appended to the closure plan §H3; session recordings retained per the usual demo-environment handling (no PHI exists in the demo data).

## 7. Logistics checklist

- [ ] Pilot run complete, timing ≤30 min confirmed
- [ ] H1 deployed to prod (delayed triangle, match-list selection live)
- [ ] H4 urgency census confirms ordinary demo load for the session window
- [ ] Recording consent line in the session intro script
- [ ] Ground-truth capture at each freeze (screenshot + occupancy/barriers API snapshot)
