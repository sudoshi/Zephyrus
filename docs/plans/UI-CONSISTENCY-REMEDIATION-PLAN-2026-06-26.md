# Zephyrus UI Consistency Remediation Plan — 2026-06-26

**Status:** Plan (no code changes). Approved direction: retire the RTDC crimson/gold
surface island to the operational System A (blue/slate).
**Goal:** 100% UI consistency across all pages — fonts, sizes, panels, gradients,
padding, spacing — and a guardrail so it stays that way.
**Source of truth:** `DESIGN.md` + `tailwind.config.js` + `resources/css/tokens-*.css`.

---

## 1. Current state (audited)

Overall **~40–50% consistent**: a correct, dominant canonical core with a heavily
contaminated periphery. This is a systematization problem, not a redesign.

| Dimension | Today | Root problem |
|---|---|---|
| Typography | ~78% | `font-bold`/`extrabold` = faux-bold (Figtree ships 400/500/600 only) — 226 uses / ~40 files. ~195 arbitrary `text-[px]` (90% in 7 files). Dead serif/mono tokens → system-serif in ~20 files (only *visibly wrong typeface*). |
| Panels / surfaces | ~35% | Canon `Panel` quarantined (5 importers). 9 competing card components (3 on legacy `bg-white/bg-gray-800`, 1 glass). 352 inline hand-rolled panels across 115 files (101 import no primitive). Sheen exists in 3 places. |
| Spacing | ~72% | 4px grid mostly followed; **structural** drift: 5 page-shells, 3 gutters, double-gutter on 33 pages, `py-12` outliers, `max-w-7xl` (1280) vs 1600px canon. |
| Color | ~35% | **Three** systems. Raw Tailwind palette in 190 files bypasses tokens. Status split ~50/50 (`healthcare-*` 1,260 vs raw red/amber/green 1,163). RTDC leaks crimson buttons + gold rings. 18 files `bg-white` with no `dark:`. |

---

## 2. Canonical system (the single target)

| Concern | Canon | Access |
|---|---|---|
| Font family | **Figtree** (Inter secondary) | `font-sans` (default on `<body>`) — no explicit family class |
| Font weights | **400 / 500 / 600 only** | `font-normal` / `font-medium` / `font-semibold`. **No `font-bold`** (700 not loaded) |
| Font sizes | Tailwind scale | `text-xs`(11) `sm`(13) `base`(14) `lg`(16) `xl`(18) `2xl`(22) `3xl`(28) `4xl`(36). No `text-[px]`. No serif/mono. |
| Headings | base layer in `app.css` | `<h1>`=2xl/600, `<h2>`=xl/600, `<h3>`=lg/600 — prefer bare tags |
| Surface | **one `Panel`** | `rounded-lg` · `bg-healthcare-surface dark:bg-healthcare-surface-dark` · `border-healthcare-border` · `shadow-sm`→`md` (dark: tonal + faint hover) · top-down sheen · 300ms · `p-4` |
| Operational color | **System A `healthcare-*`** | surface / text-primary / text-secondary / border / `healthcare-primary` (blue) |
| Status | **one four-color vocabulary** | `healthcare-critical/warning/success/info` **and** `--critical/--warning/--success/--info` resolved to identical hex (see §7) |
| Brand accent | crimson `var(--primary)` = wordmark/brand chrome only; gold `var(--accent)` = `:focus-visible` only | never operational fills |
| Spacing | 4px grid | Tailwind `p/gap/space-y` on-scale; one page-shell owns gutter (`p-4`) + `max-w-[1600px]` |

**Sanctioned exceptions (do NOT touch):** the 7 Auth pages (`resources/css/auth.css`,
Inter/rem, walled off via `:not(.guest-page…)`); the KPI status left-stripe
(`KpiTile.tsx`, redundantly encoded). The Design gallery (`Pages/Design/*`) is a
swatch page — exempt, but must stop seeding raw-color patterns.

---

## 3. Phased remediation

Each phase lands as **sequential ≤100-file commits on `main`** (per the worktree-sweep
rule — never one atomic merge). Every commit: `npx tsc --noEmit` + `npx vite build`
green before push.

### Phase 1 — Foundation (low-risk, high-leverage → ~65%)

**1.1 One surface primitive.**
- Designate the canonical surface (the `CommandCenter/Panel.tsx` spec). Make
  `Components/Dashboard/Card.jsx` (71 importers, already spec-correct) and
  `CommandCenter/Panel.tsx` delegate to a single shared `Components/ui/Surface.tsx`
  (or have `Card` re-export `Panel`'s surface) so there is ONE definition.
- **Delete** (migrate importers first): `Components/ui/Panel.jsx` (35 → Card),
  `Components/ui/MetricCard.jsx` (4), `Components/Design/panels/DropLightPanel.jsx`
  + `AnalyticsPanel.jsx` (1 each, glassmorphism — forbidden).
- **Delete dead:** `Components/ui/flowbite/Card.jsx` (0), `Components/Dashboard/MetricCard.jsx` (0).
- **Collapse** the 3 near-duplicate metric cards (`Common/MetricsCard` 19,
  `Analytics/Common/MetricsCard` 11, `ui/MetricCard` 4) into one metric component on the canon Panel.

**1.2 One page-shell gutter.**
- `Components/Dashboard/DashboardLayout.jsx:11` `<main>` → `max-w-[var(--content-max-width)] mx-auto` (remove `py-4 px-4 sm:px-6`).
- `Components/Common/PageContentLayout.jsx` becomes the sole gutter owner (`p-4`).
- Convert the ~30 Breeze/roll-your-own pages onto `PageContentLayout`: `Pages/Profile/Edit.jsx`,
  `Pages/Admin/Users/*`, `Pages/RTDC/Analytics/Trends.jsx`, `Pages/RTDC/Predictions/ResourcePlanning.jsx`,
  `Pages/Improvement/PDSADashboard.jsx`, `Pages/Improvement/Index.jsx` (strip `py-12`/`py-6`,
  `max-w-7xl`, `sm:px-6 lg:px-8`, `sm:p-6`). **Fixes the double-gutter on 33 pages at once.**

**1.3 Kill dead serif/mono tokens.**
- `resources/css/tokens-base.css`: delete `--font-display/--font-heading/--font-mono`
  defs (lines ~9–12) and the `.text-value/.text-panel-title/.text-section/.text-mono`
  utilities (lines ~118–137).
- Replace usages: `.text-value` → `text-2xl font-semibold tabular-nums`
  (`RTDC/UnitHuddle.tsx:71`, `RTDC/GlobalHuddle.tsx:16,20`, `Components/RTDC/ReliabilityTile.tsx:7`,
  `RecommendationCard.tsx:17`, `BedNeedReadout.tsx:15`); `.text-panel-title` → `text-lg font-semibold`
  (`RTDC/UnitHuddle.tsx:52,57,62`, `RTDC/BedPlacement.tsx:31,49`); `font-mono` → `tabular-nums`
  (21 uses / 13 files, heaviest `Operations/CaseManagement/CareJourneyCard.jsx` ×10);
  `var(--font-sans)` → `font-sans` (`ProcessFlowDiagram.jsx:1419,1456` — currently undefined);
  `focus.css:13` skip-link `--font-body` → `font-sans`.

**1.4 Faux-bold → semibold.** Global `font-bold`/`font-extrabold` → `font-semibold`
(226 uses; clusters: Analytics 68, RTDC 25, Improvement 10). Inline `fontWeight:'bold'`
(9) → `600`.

### Phase 2 — Color codemod (sequential, ~190 files → ~80%)

Mechanical raw→token replacement **with `dark:` pairs added**. Codemod table in §6.
Order by blast radius, smallest-risk first:
1. `Components/Analytics/*` (51 files; worst: `PatientFlow/Views/StatisticsView.jsx`,
   `PatientFlow/Views/BottlenecksView.jsx`).
2. `Pages/Improvement/*` + `Components/Process/*` (worst: `Process/VariantsViewPanel.jsx` 202,
   `Improvement/RootCause.jsx` 99, `Improvement/Bottlenecks.jsx` 50, `Process/ProcessFlowDiagram.jsx` 57).
3. `Components/Cases/*`, `Pages/Transport/*`, `Pages/Staffing/*` (`Cases/CaseList.jsx` — status
   badges `bg-blue-100 text-blue-800` with no `dark:`/token, fix first).
4. Cleanup: remove Laravel-Breeze cruft hex `#FF2D20` in `Pages/Welcome.jsx`;
   `Layouts/GuestLayout.tsx:61` `bg-[#fafbfe] dark:bg-[#0b1120]` → tokens.

### Phase 3 — Surface sweep (~115 files → ~90%+)

Replace the 352 inline `<div className="rounded-lg border bg-…">` clusters with the
canon `<Card>`/`<Panel>`. Worst: `Pages/Analytics.jsx` (27), `Transport/IntegrationSettings.tsx`
(26), `Process/ProcessSelector.jsx` (16), `Transport/Transfers.tsx` (15), `Improvement/RootCause.jsx` (10).
Drop resting `shadow-md/lg/xl` on panels (Quiet-Lift Rule); normalize `rounded-xl/2xl/3xl`
card containers → `rounded-lg` (modals may keep `rounded-xl`).

### Phase 4 — Retire RTDC to System A (approved)

Migrate the RTDC "S2" island (`Pages/RTDC/UnitHuddle.tsx`, `GlobalHuddle.tsx`,
`BedPlacement.tsx` + `Components/RTDC/RecommendationCard.tsx`, `ReliabilityTile.tsx`,
`BedNeedReadout.tsx`, `BarrierBoard.tsx`, `CareJourneySummary.tsx`, `PatientJourney.tsx`,
~10 files) off the CSS-var surface system onto System A:
- `bg-[var(--surface-raised/overlay/base)]` → `bg-healthcare-surface dark:bg-healthcare-surface-dark` (use the canon `<Panel>`).
- `var(--text-primary/secondary/muted)` → `healthcare-text-*`; `var(--border-subtle/default)` → `healthcare-border`.
- **Crimson operational buttons** `bg-[var(--primary)]` (`UnitHuddle.tsx:77`,
  `RecommendationCard.tsx:41`) → `bg-healthcare-primary`.
- **Gold emphasis ring** `ring-[var(--accent)]` (`RecommendationCard.tsx:14`) → remove (gold is focus-only).
- **Gold category color** `BarrierBoard.tsx:7` `social: 'var(--accent)'` → a `healthcare-*`/chart token.
- `rounded-[var(--radius-lg)]` → `rounded-lg`; `p-[var(--space-5)]` → `p-4`/`p-5`.
Keep `STATUS_VAR` (CommandCenter) per §7.

### Phase 5 — Spacing/type cleanup + guardrail

- Snap 195 arbitrary `text-[px]` → scale classes (§6 table). 90% in `Transport/IntegrationSettings.tsx`,
  `Transport/Transfers.tsx`, `Staffing/StaffingOffice.tsx`, `Ops/ExecutiveBrief.tsx`, `Ops/AgentInbox.tsx`.
- Snap 170 half-step utilities (`py-1.5`,`px-2.5`,`p-2.5`,`gap-1.5`…) + 32 inline px (5/9/10/3/6px,
  in `Analytics/RoomRunning`, `Analytics/PrimetimeUtilization`, `Process/ProcessFlowDiagram`,
  `ui/NivoThemeProvider`) → grid steps / `var(--space-N)`. Fix `RecommendationCard.tsx:25` `py-[2px]`.
- Grid helpers: either adopt the `tokens-base.css` `.grid-*` (currently 0 uses) or delete them and
  document inline `grid-cols-*` + `minmax(…, 1fr)` as the convention.
- **Guardrail (holds 100%):** add an ESLint rule (e.g. `no-restricted-syntax`/a tailwind plugin)
  banning raw `bg-gray-/red-/blue-/green-/amber-*`, `bg-white`, raw hex, `font-bold`, and `text-[…]`
  in `resources/js` (allowlist `auth.css`, charts). Document the canon in `CLAUDE.md` and keep the
  impeccable design hook on.

---

## 4. Consistency scorecard (projected)

| After | Typography | Surfaces | Spacing | Color | Overall |
|---|---|---|---|---|---|
| Today | 78 | 35 | 72 | 35 | ~45 |
| Phase 1 | 92 | 70 | 90 | 40 | ~65 |
| Phase 2 | 92 | 72 | 90 | 82 | ~82 |
| Phase 3 | 93 | 92 | 92 | 88 | ~91 |
| Phase 4 | 95 | 95 | 93 | 95 | ~95 |
| Phase 5 + guardrail | 98 | 97 | 97 | 97 | **~98–100** |

---

## 5. Effort & sequencing

- **Phase 1:** ~5–6 commits, 1 session. Highest leverage; do first.
- **Phase 2:** ~4–6 sequential commits (per area), 1–2 sessions.
- **Phase 3:** ~6–10 sequential commits, 1–2 sessions.
- **Phase 4:** ~2–3 commits, part of a session.
- **Phase 5:** ~3–4 commits + guardrail, part of a session.
Total: a multi-session program. Each phase is independently shippable and deployable.

---

## 6. Codemod rule tables

### Color (raw → token, add `dark:` pair)
| Raw | Canon |
|---|---|
| `bg-white` | `bg-healthcare-surface dark:bg-healthcare-surface-dark` |
| `bg-gray-50/100` | `bg-healthcare-background dark:bg-healthcare-background-dark` (or `-surface-secondary`) |
| `bg-gray-800/900` | (dark surface — pair under `dark:`) |
| `text-gray-900/800` | `text-healthcare-text-primary dark:text-healthcare-text-primary-dark` |
| `text-gray-600/500/400` | `text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark` |
| `border-gray-200/100/700` | `border-healthcare-border dark:border-healthcare-border-dark` |
| `*-red-*/rose-*` (status) | `*-healthcare-critical dark:*-healthcare-critical-dark` |
| `*-amber-*/yellow-*/orange-*` | `*-healthcare-warning dark:*-healthcare-warning-dark` |
| `*-green-*/emerald-*` | `*-healthcare-success dark:*-healthcare-success-dark` |
| `*-blue-*` (info) / (interactive) | `*-healthcare-info` / `*-healthcare-primary` |
| raw `#hex`, `bg-[#…]` | nearest token (case-by-case) |

### Typography (arbitrary → scale)
| Arbitrary | Canon |
|---|---|
| `text-[13px]/[18px]` (59) | `text-sm` |
| `text-[14px]` (19) | `text-base` |
| `text-[16px]/[22px]` (21) | `text-lg` |
| `text-[11px]` / `text-[0.6875rem]` | `text-xs` |
| `text-[10px]/[9px]/[8px]` | `text-xs` (floor) |
| `text-[12px]` (61) | `text-xs` (document the 12px gap, or add a token) |
| `text-[17px]/[15px]/[24px]/[1.35rem]…` | snap to nearest scale class |
| `font-bold`/`font-extrabold` | `font-semibold` |

### Spacing (off-grid → grid)
| Off-grid | Canon |
|---|---|
| `py-1.5`/`px-2.5`/`p-2.5`/`gap-1.5` | `py-2`/`px-2`/`p-2`/`gap-2` (or keep 2px `py-0.5` only for badges) |
| inline `padding:'9px 12px'`, `gap:'6px'`, `5px`, `3px` | `var(--space-N)` / Tailwind utilities |
| `py-[2px]` | `py-0.5` |
| `py-12`/`py-6` page tops, `max-w-7xl`, `sm:px-6 lg:px-8`, `sm:p-6` | route through `PageContentLayout` (`p-4`, `max-w-[1600px]`) |

---

## 7. Open sub-decision: one status palette

`healthcare-critical/warning/success/info` (Tailwind, System A — 1,260 uses) and
`--critical/--warning/--success/--info` (CSS vars / `STATUS_VAR` — CommandCenter) currently
resolve to **different hex** (e.g. dark critical `#EF4444` vs `#E85A6B`). For 100% consistency,
**make them identical**: set the Tailwind `healthcare-*` status tokens and the `--*` CSS vars to
the same values (recommend the DESIGN.md vocabulary: teal success `#2DD4BF`, amber `#E5A84B`,
coral `#E85A6B`, sky `#60A5FA` in dark). Then it doesn't matter which a component uses — class or
`var()` — and the P3 "two reds in one tile" issue is solved at the system level. Decide before Phase 2.

---

## 8. Verification & commit protocol (every commit)

1. `npx tsc --noEmit` — clean.
2. `npx vite build` — green (stricter than tsc; catches unresolved imports).
3. `npx vitest run` for any touched component with tests.
4. ≤~100 files per commit; conventional message; rebase on `origin/main` if behind; list any deletions.
5. Deploy per phase via `./deploy.sh` (frontend-only; no migrations); verify the Zephyrus vhost.
