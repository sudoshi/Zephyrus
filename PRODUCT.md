# Product

## Register

product

## Users

Zephyrus serves a **mixed command-center audience**, switched between by role rather
than split across separate products:

- **House-wide operations leaders** — nursing supervisors, bed managers, and
  command-center staff managing demand vs. capacity and patient flow across the
  entire hospital.
- **Frontline unit staff** — charge nurses and ED clinicians working a single
  department live, mid-shift, glancing rather than studying.
- **Executives & administrators** — CMO / COO / CNO reviewing throughput,
  utilization, and outcomes; they need numbers that survive scrutiny.

**Context of use:** hospital operations in real time — often glanced at on wall
displays and shared workstations, under time pressure, with high clinical and
financial stakes. The same surface is read at a 3-second glance during a surge
and pored over line-by-line in a Monday review.

**Job to be done:** maintain situational awareness of capacity and flow, surface
the one thing that needs action *now*, analyze trends to drive process
improvement, and defend operational decisions with rigorous data.

## Product Purpose

Zephyrus is a healthcare operations platform that unifies four hospital workflows
— **Emergency Department, Real-Time Demand & Capacity (RTDC), Perioperative, and
Process Improvement** — into a single command center. It replaces fragmented
legacy tooling with real-time monitoring, predictive alerts, and process mining
to optimize patient flow, bed and OR capacity, staffing, and throughput.

Success looks like: staff *trust it at a glance* during a busy shift, it surfaces
the right action at the right moment without crying wolf, and leaders can stand
behind operational decisions because the data underneath is defensible.

## Brand Personality

Balanced between **clinical / precise / trustworthy** and **authoritative /
executive / polished** — serious clinical infrastructure with boardroom-grade
finish.

- **Voice:** direct, calm, data-honest. Never breathless, never cute. Confidence
  through restraint.
- **Three words:** Rigorous. Composed. Defensible.
- **The dual test:** it must be the platform a CMO trusts in a performance review
  *and* the one a charge nurse trusts mid-shift. Neither audience is the
  afterthought.
- **Emotional goal (role/context-adaptive):** emphasis shifts to who needs what —
  calm command-and-control for the overview, unmistakable urgency for what's
  breaching, analytical depth for drill-down, executive confidence for reporting.
  The feeling is tuned to the moment, not applied uniformly.

## Anti-references

This should NOT look or feel like any of these:

- **Legacy hospital EHR** (Epic / Cerner dense gray grids, tiny inscrutable
  toolbars, 1998 enterprise chrome) — this is the thing Zephyrus replaces; looking
  like it forfeits the entire pitch.
- **Consumer SaaS / startup** (playful gradients, blobby illustrations, emoji,
  marketing-style hero-metric cards) — too casual for clinical operations.
- **Alarm-fatigue dashboard** (everything red, blinking, urgent at once; no
  hierarchy) — the UI staff learn to ignore. The opposite of the goal.
- **Generic admin template** (Bootstrap / Material defaults, identical card grids,
  AI-slop sameness with no point of view) — competent but anonymous.

## Design Principles

1. **Adaptive emphasis by role and moment.** The same data surfaces differently
   depending on who's looking and what the situation demands. Calm is the default
   state; urgency is earned, not ambient.

2. **Earned urgency — signal is scarce currency.** Color and motion are rationed.
   If everything alarms, nothing does. Reserve the loudest treatment for what
   genuinely needs action now; actively design against alarm fatigue.

3. **Defensible at a glance *and* in depth.** Every number must hold up both at
   3-second glance distance on a wall display and under line-by-line executive
   review. Favor provenance and rigor over a clean-looking headline.

4. **Replace the EHR, don't reskin it.** Earn trust by being demonstrably better
   than the legacy tools, not by mimicking them. Pursue density *with* clarity —
   never density as clutter.

5. **Composed under pressure.** The interface stays legible and orderly precisely
   when the hospital is not. Restraint is the feature, not a missed opportunity for
   decoration.

## Accessibility & Inclusion

**Target: WCAG 2.2 AA (pragmatic).**

- AA contrast across both themes (dark mode is the default; light mode is fully
  supported).
- **Status is never conveyed by color alone.** Clinical red / amber / green
  semantics are always paired with an icon, label, or shape — color-blind
  redundancy is mandatory on status and alerts, applied generously elsewhere.
- Honor `prefers-reduced-motion` with crossfade or instant alternatives for every
  animation.
- Legible at glance distance on the varied, often imperfect monitors found on
  clinical floors.
