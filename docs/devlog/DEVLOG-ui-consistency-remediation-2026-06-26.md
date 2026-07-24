# DEVLOG — UI Consistency Remediation (2026-06-26)

Executed `docs/plans/UI-CONSISTENCY-REMEDIATION-PLAN-2026-06-26.md` end-to-end. Goal:
100% UI consistency (fonts, sizes, panels, gradients, padding, spacing) + a
guardrail so it stays that way. Raw-color occurrences **4261 → ~600** (the
remainder are documented exceptions). Projected consistency ~45% → ~95%+.

All work landed as sequential build-green commits on `main` (10 commits),
pushed per phase, deployed to the Zephyrus prod vhost (Phase 1-2 mid-way for
visual review, Phases 3-5 at the end). Every commit: `tsc --noEmit` + `vite
build` green; CommandCenter vitest 63/63 throughout.

## What shipped

**Phase 1 — Foundation**
- 1.3 Removed dead serif/mono font tokens (`--font-display/-heading/-body/-mono`
  + `.text-value/.text-panel-title/.text-section/.text-mono`) — the only
  *visibly-wrong typeface*. `font-mono` → `tabular-nums` (operational files).
- 1.4 `font-bold`/`font-extrabold` → `font-semibold` (227 / 90 files; Figtree has
  no 700) + inline `fontWeight:'bold'` → 600.
- 1.1 ONE canonical surface primitive `Components/ui/Surface.tsx`; `Card`,
  `CommandCenter/Panel`, `ui/Panel`, `ui/MetricCard` all delegate to it; killed
  glassmorphism (`AnalyticsPanel`/`DropLightPanel` deleted, `backdrop-blur`);
  merged the duplicate metric cards; deleted dead `ui/flowbite/Card`.
- 1.2 One page-shell gutter: `DashboardLayout` centers only (`max-w-[1600px]`),
  `PageContentLayout` is the sole `p-4` gutter — fixed double-gutter on 33 pages;
  retired 15 Breeze `py-12`/`max-w-7xl` shells.

**§7 — Status palette unified** to the DESIGN.md teal/amber/coral/sky
rationed-urgency vocabulary across all three systems (Tailwind `healthcare-*`,
the `--critical/…` CSS vars, the chart `--healthcare-*` vars). They already
matched in light mode; only the dark values were unified.

**Phase 2 — Color codemod** (~190 files, raw Tailwind palette → `healthcare-*`
tokens with `dark:` pairs) via Opus-4.8 agent swarms (5 commits), every cluster
audited (raw-color count, no double `dark:`, no text-white-on-surface).

**Phase 3 — Surface sweep.** Surface *consistency* was already delivered by 1.1
+ 2 (one primitive, all token-colored), so the plan's "352 panels → Card" was
moot/inappropriate post-codemod (many "panels" are list rows/chips). Did the
actionable part: Quiet-Lift (resting heavy shadows → `shadow-sm` on true panels).

**Phase 4 — Retired the RTDC island** (9 files) off the CSS-var system to System
A: surfaces/text/border → `healthcare-*`; **crimson operational buttons →
`healthcare-primary`**, **gold emphasis ring → System-A blue**, gold category →
`info` (Two-System Rule). Kept the §7-unified status vars.

**Phase 5 — Spacing + guardrail.** Snapped ~200 arbitrary `text-[Npx]` → the
Tailwind scale. Added the **Token Canon** section to `CLAUDE.md` and
`scripts/check-ui-canon.sh` (fails on faux-bold / arbitrary font sizes); the
impeccable design hook enforces the broader canon on every edit.

## Bugs caught & fixed along the way
- **Silent light-mode bug:** converting dark containers (`bg-gray-900`) holding
  unconditional `text-white` to a theme surface produced white-on-white in light
  mode (tooltips, chart titles, ProcessFlowDiagram metric insets). Audited the
  whole codemod for it; fixed; hardened the agent spec to leave dark containers
  dark when they hold light text.
- **`tailwind.config.js` duplicate-key bug:** the `healthcare` object declared
  `surface:` twice; the later key silently shadowed `surface.hover`, so
  `bg-healthcare-surface-hover` (51 usages) resolved to nothing. Restored
  `surface.hover` in the winning block.
- Plan's "dead" `Dashboard/MetricCard.jsx` was actually imported via a relative
  `./MetricCard` (grep blind spot) — restored, not deleted; it's already canon.

## Documented exceptions (left intentionally)
Auth/guest pages (`auth.css`, `Components/Auth/*`, `GuestLayout` — deliberate
indigo/blue/cyan), `Pages/Design/*` swatch gallery, categorical chart palettes /
Nivo schemes / reactflow handle ports / dynamic `bg-${…}` classes, the
intentionally-dark CommandPalette, half-step spacing (4px-grid-adjacent
fine-tuning), `Pages/Welcome.jsx` (guest landing).

## Commits
1.3 `589b097` · 1.4 `72d22d2` · 1.1 `ef5f0e6` · 1.2 `1bf77cb` · §7 `f550d59` ·
P2-Analytics `d4cfcb8` · P2-Improvement/Process `1ea8eb6` · P2-RTDC/+ `1faba0b` ·
P2-longtail+config `6300ea0` · P3 `43f8e7d` · P4 `dc6a3b9` · P5 `20737cb`.
