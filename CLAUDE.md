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

### Token Canon — DO NOT regress (the consistency guardrail)

The 2026-06-26 UI-consistency remediation normalized the whole app onto these
tokens. The **impeccable design hook** flags violations on every edit — keep it on.

- **Typography:** Figtree only via `font-sans` (default on `<body>`) — never name a
  family. Weights **400/500/600** only: `font-normal`/`font-medium`/`font-semibold`.
  **No `font-bold`/`font-extrabold`** (700/800 are not loaded → faux-bold). Sizes are
  the Tailwind scale (`text-xs`…`text-4xl`); **no `text-[Npx]`**. No serif/mono tokens
  (deleted). Metrics/IDs: `tabular-nums` (not `font-mono`).
- **Surfaces:** ONE primitive — `Components/ui/Surface.tsx`. Use `<Card>` /
  `<Panel>` (both delegate to Surface) or the `bg-healthcare-surface
  dark:bg-healthcare-surface-dark` + `border-healthcare-border` + `shadow-sm`
  treatment. **Never** `bg-white` / `bg-gray-*` surfaces or glassmorphism
  (`backdrop-blur`). Resting panels = `shadow-sm` (Quiet-Lift); only floating
  elements (modals/dropdowns/tooltips) get `shadow-lg`.
- **Color:** `healthcare-*` tokens with a `dark:` pair, always. **No raw Tailwind
  palette** (`bg-gray/red/blue/green/amber/indigo/slate/white-*`) in `resources/js`.
  Status = `healthcare-critical/warning/success/info` (= the `--critical/…` vars;
  unified to teal/amber/coral/sky in dark). Interactive blue =
  `healthcare-primary`. White text only on a solid colored fill, never on a surface.
- **Spacing:** 4px grid (Tailwind `p/gap/space-y` scale). One gutter owner —
  `Components/Common/PageContentLayout` (`p-4`); `DashboardLayout`/`AuthenticatedLayout`
  only center at `max-w-[var(--content-max-width)]` (1600px). No double gutter.
- **Sanctioned exceptions:** the 7 Auth pages + `Components/Auth/*` + `GuestLayout`
  (`auth.css`, deliberate indigo/blue/cyan — don't recolor); `Pages/Design/*` (swatch
  gallery); categorical chart palettes / Nivo schemes / reactflow handle ports /
  dynamic `bg-${…}` classes (data-driven, not status). The RTDC status CSS-vars
  (`var(--critical)` etc.) are fine — they resolve to the same tokens.
  **Zephyrus 2.0 wall mode:** `text-[11px]` micro-captions are sanctioned ONLY inside
  `Components/cockpit/` (dense wall-display captions — see docs/ZEPHYRUS-2.0-PLAN.md
  Part IV §2); everywhere else the `text-xs` floor holds.
- **Scripted floor:** `scripts/check-ui-canon.sh` hard-fails on faux-bold,
  `text-[Npx]` (outside the cockpit exception), `oklch(`, and any NEW
  `backdrop-blur` file; the raw-palette count is a ratchet (baseline may only go
  down). The cockpit reference prototype's tokens (IBM Plex, OKLCH, cyan, a fifth
  green) NEVER enter the codebase — philosophy adopted, tokens rejected.

Run `/impeccable <command>` (craft, critique, audit, polish, live, …) for design
work; every command reads PRODUCT.md + DESIGN.md first.
