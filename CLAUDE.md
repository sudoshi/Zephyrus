# CLAUDE.md — Zephyrus

Project guidance for Claude Code. Engineering/build/deploy conventions live in
[AGENTS.md](./AGENTS.md); this file points at the design context.

## Design Context

Zephyrus is a **product**-register surface (a hospital operations command center —
ED, RTDC, Perioperative, Process Improvement). Before any UI work, read:

- **[PRODUCT.md](./PRODUCT.md)** — strategic context: mixed command-center users
  (frontline / ops-leaders / executives via the role switcher), the "rigorous,
  composed, defensible" personality, anti-references, and the 5 design principles.
- **[DESIGN.md](./DESIGN.md)** — the visual system (+ `.impeccable/design.json`
  sidecar). North Star: **"The Operations Bridge."**

### The non-negotiables
- **Two-System Rule:** the **blue/slate** `healthcare-*` palette governs operational
  surfaces and interaction; **crimson `#9B1B30` + gold `#C9A227`** is the Acumenus
  brand/heritage + focus layer only. Don't promote crimson to a dashboard primary or
  let screens drift between the two systems.
- **Earned urgency:** ration status color (teal/amber/coral/sky); reserve coral-red
  for real breaches. Never build an alarm-fatigue dashboard.
- **Status never by color alone:** always pair with an arrow, icon, or label.
- **Dark-default**, dual-theme; metrics always `tabular-nums`; gold `:focus-visible`.
- Accessibility target: **WCAG 2.2 AA (pragmatic)**.

Run `/impeccable <command>` (craft, critique, audit, polish, live, …) for design
work; every command reads PRODUCT.md + DESIGN.md first.
