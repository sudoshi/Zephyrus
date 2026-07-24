# Zephyrus — Screen Real Estate & UI Density Tightening Plan

**Author:** Claude (UX/UI assessment)
**Date:** 2026-06-21
**Goal:** Maximize information density and best-practice presentation across **all** Zephyrus
pages by tightening panels, fonts, spacing, controls, and layout chrome — the same
token-driven density approach applied to Medgnosis.

---

## 0. TL;DR — What's wrong and the strategy

Zephyrus is a clinical operations platform (RTDC, ED, Perioperative, Analytics,
Improvement). These are **dense operational dashboards** — the kind of UI where every
reclaimed row matters (more beds, more cases, more KPIs per viewport). Today the app is
tuned like a *spacious marketing dashboard*: oversized fonts, 24px padding everywhere,
44px-tall controls forced globally, and five competing layout wrappers that stack their
padding.

**The fix is almost entirely systemic, not page-by-page.** ~80% of the wins come from
~8 shared files (design tokens, UI primitives, layout shells, global CSS). The page sweep
that follows is mostly *removing* now-redundant local overrides, not rewriting pages.

**Estimated reclaim: 20–30% vertical space and ~1 type-step smaller across 89 pages**,
with no loss of readability and full accessibility on touch devices.

---

## 1. Diagnosis — root causes (evidence-based)

The audit covered all 89 application pages (excluding the `Pages/Design/*` sandbox) and the
shared component library. Findings, with prevalence counts:

### 1.1 Two competing **type scales** (both defined, fighting each other)
- **Tailwind override** (`tailwind.config.js:127-139`) — comment literally says *"Larger
  default sizes for better readability"*. Base = **16px**, `text-2xl`=24px, `text-3xl`=30px.
  This is what the **89 `.jsx` pages consume** (`text-2xl` ×130, `text-3xl` ×29, `text-lg` ×209).
- **Token scale** (`tokens-base.css:14-25`) — the *intended* Acumenus Clinical scale. Base =
  **14px**, `--text-2xl`=22px, `--text-3xl`=28px. Only the new `.tsx` pages
  (`GlobalHuddle`, `BedPlacement`, `UnitHuddle`) consume it.
- **Result:** every Tailwind text class renders ~1 step larger than the design system intends.

### 1.2 Global **44px touch-target tax** (the single biggest silent waster)
- `app.css:120-126`: `button, [role="button"], input, select { min-h-[44px]; min-w-[44px] }`
  applied to **every control** in the app (except `.guest-page`).
- Forces 44px height **and width** on every filter dropdown, table action button, toolbar
  control, icon button, and form field — on desktop, where the pointer is a mouse.
- Inflates every filter bar, toolbar, and form by 8–12px per control row. Invisible in page
  code (only 1 literal `min-h-[44px]` in JSX) because it is enforced globally in the base layer.

### 1.3 **Card / panel primitives** default to `p-6` (24px) and multiply everywhere
- `Components/ui/Card.jsx` — `CardHeader` `p-6`, `CardContent` `p-6`, `CardFooter` `p-6`
  (a header+content card spends 48px on padding alone).
- `Components/Dashboard/Card.jsx` — same `p-6` header + content.
- `.healthcare-card` global class = `p-6` — used **×82**.
- `Components/Common/MetricsCard.jsx` — `Card.Content p-6` + `mt-5`.
- `Components/ui/Panel.jsx` / `.healthcare-panel` — `p-4` + `mb-4` header.
- `Components/ui/dialog.jsx` — `p-6` + `gap-4`.
- Prevalence: `p-6` ×93, `p-4` ×324, `p-8` ×25, `px-6` ×262.

### 1.4 Loose **spacing rhythm** as the default
- `space-y-6` ×127, `gap-6` ×124, `mb-6` ×85, `mb-8` ×15, `gap-8` (Improvement pages).
- 24px is used as the *default* gap; it should be reserved for top-level section breaks only.

### 1.5 **Five competing layout wrappers** with stacked padding
| Wrapper | Used by | Padding it adds |
|---|---|---|
| `Components/Dashboard/DashboardLayout.jsx` | 31 pages | `py-6 px-6` |
| `Components/Common/PageContentLayout.jsx` | 28 pages | `p-6` + `mb-6` header |
| `Layouts/AuthenticatedLayout.tsx` | 18 pages | content shell (token-based) |
| `RTDC/RTDCPageLayout.jsx` | 13 pages | own padding |
| `Layouts/AnalyticsLayout.jsx` | 6 pages | `py-6` → `px-4/6/8` → `p-6` (triple-nested) |
- **`DashboardLayout` + `PageContentLayout` stack = 48px before content even starts.**
- `AnalyticsLayout` compounds three padding layers before the card's own `p-6`.

### 1.6 Orphaned layout tokens (system already knows the right numbers)
- `--topbar-height: 56px` is **defined but never consumed** — the navbar hardcodes `h-16` (64px).
- `--content-max-width: 1600px`, `--content-padding`, `--panel-padding` exist but most pages
  bypass them with hardcoded Tailwind classes.
- **The design system already specifies the tight values; the pages just don't use them.**

### 1.7 Fixed / oversized heights & empty states
- `RoomRunning OverviewView.jsx:219` — `h-192` (**768px**) single chart.
- `BlockSchedule.jsx:107` — `h-[600px]` calendar placeholder.
- `TrendChart.jsx:44` — every chart `h-[400px]` regardless of series count.
- Chart placeholders `h-[350px]`; `EDPlaceholder.jsx:12` empty state `py-16` (64px).
- `DischargePriorities.jsx:52` — `h-[calc(35vh-2rem)]` tiers cramp content to 3–5 items where 6–8 fit.
- `CompactTabPanel.jsx:94` — `h-[400px]` fixed.

### 1.8 Navigation chrome
- `TopNavbar.tsx:33` — `h-16` (64px) navbar, logo `h-9`; nav links `px-3 py-2`.
- `MegaMenuPanel.tsx` — items `py-1.5`, could be `py-1` for ~20% more items per panel.

### 1.9 What's already good (preserve & propagate)
- The **`Compact*` RTDC components** (`CompactAlerts`, `CompactCapacityOverview`,
  `CompactStaffingOverview`, `CompactTabPanel`) are an existing in-house density pattern:
  `p-4`, `space-y-1/2`, collapsible previews, single-line status rows. **This is the target
  density** — standardize it, don't reinvent it.
- Body text is already mostly `text-sm` (×1493) / `text-xs` (×554) — good; the problem is
  headings, padding, controls, and chrome, not body copy.
- The `.tsx` token-consuming pages prove the token path works end-to-end.

---

## 2. Target density system (the decisions)

These are the concrete before→after token values. Everything in Phase 1 implements this table.

### 2.1 Type scale — align Tailwind to the token scale (1 step tighter)
Edit `tailwind.config.js` `fontSize` to match `tokens-base.css`. This retunes all 89 pages'
typography with **zero page edits**.

| Class | Before (px) | After (px) | Source token |
|---|---|---|---|
| `text-xs` | 12 | 11 | `--text-xs` |
| `text-sm` | 14 | 12 | `--text-sm` |
| `text-base` | 16 | 14 | `--text-base` |
| `text-lg` | 18 | 16 | `--text-lg` |
| `text-xl` | 20 | 18 | `--text-xl` |
| `text-2xl` | 24 | 22 | `--text-2xl` |
| `text-3xl` | 30 | 28 | `--text-3xl` |
| `text-4xl` | 36 | 36 | unchanged |

> ⚠️ `text-sm`→12px and `text-xs`→11px is aggressive given they are the most-used classes
> (2000+ uses). **Validate at a desk monitor first.** Fallback option if 12px body feels small:
> keep `text-sm`=13px, `text-xs`=11px (a half-step compromise). Decision gated in Phase 1.

### 2.2 Spacing / layout tokens
| Token | Before | After | Notes |
|---|---|---|---|
| `--content-padding` | `space-6` (24px) | `space-4` (16px) | page gutters |
| `--panel-padding` | `space-5` (20px) | `space-4` (16px) | card interior |
| `--topbar-height` | 56px (unused) | 56px (now wired) | navbar consumes it |
| `--content-max-width` | 1600px | 1600px | unchanged |
| Default page rhythm | `space-y-6` | `space-y-4` | `space-y-6` reserved for major section breaks |

### 2.3 Controls (touch targets)
- Replace the global 44px rule with a **pointer-aware** rule:
  - `@media (pointer: coarse)` (touch) → keep `min-height: 44px` for accessibility.
  - Default (mouse/desktop) → controls size naturally; `button`/`input`/`select` default
    min-height **32–36px**. Never apply `min-width: 44px` globally (this is what bloats toolbars).
- `button.jsx`: default `h-10`→`h-9` (36px); add `xs: h-7 px-2 text-xs` for dense toolbars.
- This satisfies WCAG 2.5.8 (AA, 24px min) on desktop and 2.5.5 (AAA, 44px) on touch.

### 2.4 Card / panel primitives
- `ui/Card.jsx` + `Dashboard/Card.jsx`: header/content/footer `p-6`→`p-4`; add a `density`
  prop (`comfortable` = p-4 default, `compact` = p-3) so dense boards opt in further.
- `.healthcare-card` `p-6`→`p-4`; `.healthcare-panel` keep `p-4`.
- `MetricsCard`/`MetricCard`: `p-6`→`p-4`, `mt-5`→`mt-3`, value `text-3xl`→`text-2xl`,
  icon box `w-12 h-12`→`w-9 h-9`.
- `dialog.jsx`: content `p-6`→`p-4`, `gap-4`→`gap-3`.
- `CardTitle`/panel titles: `text-lg`→`text-base font-semibold`.

### 2.5 Charts & fixed heights
- `TrendChart` default height `400`→`280`; expose `height` prop already; single-series ⇒ 240.
- Cap placeholder/empty states: `h-192`→`h-80`, `h-[600px]`→`h-96`, `py-16`→`py-8`.
- Replace `h-[calc(NNvh)]` tier heights with `max-h-*` + `overflow-y-auto`.

### 2.6 Navbar
- `h-16`→`h-14`, consume `--topbar-height`; logo `h-9`→`h-8`; mega-menu items `py-1.5`→`py-1`.

---

## 3. Phased execution plan

Ordered for **maximum leverage first, lowest risk**. Each phase is independently shippable and
verifiable (`npx tsc --noEmit` **and** `npx vite build` — vite is stricter; then visual check).

### Phase 0 — Baseline & guardrails (no visual change)
- [ ] Capture before/after screenshots of 8 representative pages (RTDC GlobalHuddle, Dashboard,
      Analytics/ORUtilization, ED/Operations/Triage, Improvement/PDSADashboard,
      Operations/RoomStatus, Admin/Users, Auth/Login) at 1440×900 and 1920×1080.
- [ ] Confirm baseline `tsc` + `vite build` are green.
- [ ] Re-read `.claude/rules/auth-system.md` — **auth pages are protected**: density-only,
      no structural/functional changes, never remove the Create Account CTA / ChangePasswordModal.

### Phase 1 — Global token retune (≈70% of total impact, ~6 files)
*One commit, app-wide effect, zero page edits. This is the heart of the work.*
- [ ] **1a** `tailwind.config.js` — retune `fontSize` to the token scale (§2.1).
- [ ] **1b** `tokens-base.css` — `--content-padding`→`space-4`, `--panel-padding`→`space-4` (§2.2).
- [ ] **1c** `app.css` — replace global 44px control rule with pointer-aware rule (§2.3);
      this is the single highest-impact line in the codebase.
- [ ] **1d** `app.css` — heading defaults (`h1` `text-2xl`→ keep but now 22px; verify hierarchy).
- [ ] **Gate:** screenshot diff the 8 baseline pages. Decide `text-sm` 12px vs 13px compromise.
      tsc + vite build green. **Checkpoint with user before proceeding** (typography is opinionated).

### Phase 2 — Shared UI primitives (≈15% impact, ~8 files)
- [ ] **2a** `ui/Card.jsx` — `p-6`→`p-4` header/content/footer; add `density` prop; title `text-base`.
- [ ] **2b** `Dashboard/Card.jsx` — same treatment.
- [ ] **2c** `Common/MetricsCard.jsx` + `Dashboard/MetricCard.jsx` + `ui/MetricCard.jsx` —
      padding, `mt-5`→`mt-3`, value `text-3xl`→`text-2xl`, icon box shrink.
- [ ] **2d** `ui/Panel.jsx` — header `mb-4`→`mb-3`, title `text-lg`→`text-base`.
- [ ] **2e** `ui/dialog.jsx` + `Common/Modal.jsx` — `p-6`→`p-4`, `gap-4`→`gap-3`.
- [ ] **2f** `ui/button.jsx` — default `h-10`→`h-9`; add `xs` size; `ui/table.jsx` row padding
      `px-6 py-4`→`px-4 py-2.5`.
- [ ] **2g** `.healthcare-card` `p-6`→`p-4` in `app.css` (affects 82 usages).
- [ ] **Gate:** tsc + vite build; spot-check Dashboard, RTDC BedTracking, Admin/Users.

### Phase 3 — Layout shell consolidation (≈10% impact, structural)
*Goal: one source of truth for page padding/max-width; eliminate stacked padding.*
- [ ] **3a** Create canonical `Components/Common/AppPage.tsx` (or extend `PageContentLayout`):
      owns `--content-padding`, `--content-max-width`, and the title/subtitle/actions header
      block at `text-2xl`/`mb-4`.
- [ ] **3b** `DashboardLayout.jsx` — drop its `py-6 px-6` (defer padding to AppPage); keep only
      structural concerns. Fixes the 48px double-padding stack.
- [ ] **3c** `AnalyticsLayout.jsx` — collapse triple-nested padding to a single AppPage gutter.
- [ ] **3d** `RTDCPageLayout.jsx` — align to AppPage padding; preserve huddle-specific grid.
- [ ] **3e** `PageContentLayout.jsx` — make it a thin alias of AppPage (28 pages already use it,
      so it becomes the migration vehicle — no per-page churn).
- [ ] **3f** Wire `AuthenticatedLayout.tsx` header padding to the new `--content-padding`.
- [ ] **Gate:** verify no page has lost its gutter or doubled it; tsc + vite build.

### Phase 4 — Navigation chrome
- [ ] **4a** `TopNavbar.tsx` — `h-16`→`h-14` (consume `--topbar-height`), logo `h-9`→`h-8`,
      links `py-2`→`py-1.5`.
- [ ] **4b** `MegaMenuPanel.tsx` / `NavMegaMenu.tsx` — items `py-1.5`→`py-1`; panel `p-4`→`p-3`.
- [ ] **4c** `MobileNavMenu.tsx` / `UserMenu.tsx` — align row heights (keep ≥44px on touch).

### Phase 5 — Charts & fixed-height cleanup
- [ ] **5a** `Common/TrendChart.jsx` default `400`→`280`.
- [ ] **5b** `RoomRunning/Views/OverviewView.jsx` — `h-192`→`h-96`; even out the two charts.
- [ ] **5c** `BlockSchedule.jsx` `h-[600px]`→`h-96`; `EDPlaceholder.jsx` `py-16`→`py-8`.
- [ ] **5d** RTDC fixed tiers — `h-[calc(35vh)]`/`h-[400px]` → `max-h-*` + `overflow-y-auto`
      (`DischargePriorities.jsx`, `CompactTabPanel.jsx`).
- [ ] **5e** Audit all `h-[*]`/`min-h-[*]` literals; cap or make responsive.

### Phase 6 — Per-section page sweep (cleanup, mostly *removing* redundant overrides)
After Phases 1–3, most pages auto-tighten. This phase removes now-redundant local
`p-6`/`space-y-6`/`text-2xl`/`gap-8` that duplicate or fight the new defaults, and applies
`compact` density where boards are info-dense. Work section-by-section (see §4 checklist).
- [ ] 6a Dashboard cluster (6 pages)
- [ ] 6b RTDC cluster (~20 pages/components) — apply `Compact*` density widely
- [ ] 6c Analytics cluster (10 pages)
- [ ] 6d ED cluster (9 pages)
- [ ] 6e Improvement cluster (~16 pages) — worst `gap-8`/`space-y-8` offenders
- [ ] 6f Operations / Predictions / Cases / RoomStatus / BlockSchedule
- [ ] 6g Admin / Profile (tables, forms)
- [ ] 6h Auth (density-only, **respect auth-system.md** — see §5)

### Phase 7 — Verification, regression, ship
- [ ] After-screenshots vs Phase 0; confirm ≥20% vertical reclaim on dense pages, no clipping.
- [ ] Accessibility: verify touch targets ≥44px under `(pointer: coarse)` emulation; contrast
      unchanged; focus rings intact; `prefers-reduced-motion` honored.
- [ ] Dark mode parity check (the app defaults to dark).
- [ ] Responsive check at 1280 / 1440 / 1920 widths.
- [ ] `npx tsc --noEmit` + `npx vite build` green.
- [ ] Deploy via `./deploy.sh --frontend`; add a devlog under `docs/devlog/`.

---

## 4. Per-section page sweep checklist (all 89 pages)

Legend: **L** = layout wrapper today · priority **H/M/L**.

### Dashboard (DashboardLayout + PageContentLayout)
- [ ] `Dashboard.jsx` · H — `space-y-6`→`4`, metric grid `gap-6`→`4`
- [ ] `Dashboard/RTDC.jsx`, `ED.jsx`, `Perioperative.jsx`, `Improvement.jsx`, `Superuser.jsx` · M
- [ ] `Components/Dashboard/*` — `MonthToDateSection` `space-y-8`→`4`, charts 350→250;
      `LastMonthSection` `mb-8`→`4`; `Stats.jsx` icon `w-12`→`w-8`, value `text-3xl`→`2xl`;
      `ImprovementCard` `p-6`→`4`, count `text-2xl`→`xl`

### RTDC (RTDCPageLayout / AuthenticatedLayout / token .tsx)
- [ ] `Analytics/Trends.jsx`, `Utilization.jsx` · H — `py-12`→`py-6`, compact card
- [ ] `DischargePriorities.jsx` · H — viewport-height tiers → `max-h-96`
- [ ] `BedTracking.jsx` · M — flat metric grid, drop Card wrapper overhead
- [ ] `GlobalHuddle.tsx`, `BedPlacement.tsx`, `UnitHuddle.tsx` · L — already token-based; verify
- [ ] `BedPlacement.jsx`, `DischargePrediction.jsx`, `AncillaryServices.jsx`, `ServiceHuddle.jsx`,
      `ServicesHuddle.jsx`, `Analytics/*`, `Predictions/*`, `Operations/GlobalHuddle.jsx` · M
- [ ] `Components/RTDC/*` — standardize on `Compact*` density; `CompactTabPanel` height; `AlertCard`
      icon box; `CapacityTimelinePanel` `space-y-6`→`4`

### Analytics (AnalyticsLayout + PageContentLayout)
- [ ] `Analytics.jsx` + 9 sub-pages · H — KPI grids `gap-6`→`4`, chart heights
- [ ] `AnalyticsLayout.jsx` · H — collapse nested padding (Phase 3c)
- [ ] `Components/Analytics/AnalyticsFilters.jsx`, `HierarchicalFilters.jsx` · H — compact filter
      bars (`space-y-4`→`2`, grid selects, `mb-6`→`4`); `DateRangeSelector` `p-4`→`p-2`

### ED (DashboardLayout)
- [ ] `ED/Analytics/{Flow,Resources,WaitTime}.jsx` · M
- [ ] `ED/Operations/{Resources,Treatment,Triage}.jsx` · M
- [ ] `ED/Predictions/{Acuity,Arrival,Resources}.jsx` · M
- [ ] `Components/ED/EDPlaceholder.jsx` · H — `py-16`→`py-8`

### Improvement (DashboardLayout / PageContentLayout) — worst loose-spacing cluster
- [ ] `Improvement/Index.jsx`, `Overview.jsx`, `Active.jsx`, `Bottlenecks.jsx`, `Library.jsx`,
      `Opportunities.jsx`, `Process.jsx`, `RootCause.jsx` · H — `gap-8`/`space-y-8`→`6`/`4`
- [ ] `Improvement/PDSADashboard.jsx`, `PDSA/*` (Index, Show, BarriersTab, modals,
      CareIssuesModal, CreatePDSACycleModal, DischargeFailuresTab, PDSACycleManagementPage) · M
- [ ] `Components/Process/*` — panels/cards `p-6`→`4`, `ProcessStatisticsCards`, timelines

### Operations / Predictions / standalone
- [ ] `Operations/{BlockSchedule,CaseManagement,RoomStatus}.jsx`, `BlockSchedule.jsx`,
      `Cases.jsx`, `RoomStatus.jsx` · M — `space-y-6`→`4`, `h-[600px]` cap (Phase 5)
- [ ] `Predictions.jsx` + `Predictions/{DemandAnalysis,ResourcePlanning,UtilizationForecast}.jsx` · M
- [ ] `Components/{RoomStatus,BlockSchedule,Cases}/*` — row/card padding

### Admin / Profile (forms & tables)
- [ ] `Admin/Users/{Index,Create,Edit}.jsx` · M — table `px-6 py-4`→`px-4 py-2.5`, header `mb-6`→`4`
- [ ] `Profile/Edit.jsx` + Partials · M — `py-12`→`py-6`, `space-y-6`→`4`, card `sm:p-8`→`sm:p-6`

### Auth (GuestLayout) — DENSITY ONLY, see §5
- [ ] `Auth/Login.jsx` · M — card `p-7`→`p-5`, header `mb-8`→`mb-6`, inputs `py-3`→`py-2`
      (**keep Create Account CTA + Demo credentials block — do not remove**)
- [ ] `Auth/Register.jsx` · M — `p-7`→`p-5`, `mb-8`→`mb-6` (**no password fields — temp-password flow**)
- [ ] `Auth/ChangePassword.jsx` · M — icon `h-14 w-14`→`h-12 w-12`, `mb-4`→`mb-3`
- [ ] `Auth/{ForgotPassword,ResetPassword,ConfirmPassword,VerifyEmail}.jsx` · L
- [ ] `GuestLayout.tsx` · M — logo `h-16`→`h-12`, `mb-10`→`mb-6`, footer `mt-12`→`mt-8`

### Excluded
- `Pages/Design/*` (component sandbox), `Pages/Examples/*`, `Welcome.jsx` — not in production nav.

---

## 5. Risks & guardrails

1. **Auth system is protected** (`.claude/rules/auth-system.md`). Phase 6h is **density-only**:
   never remove the "Create Account" CTA, never make `ChangePasswordModal` dismissable, never
   add password fields to Register, never touch the must-change-password redirect. Spacing/font
   tweaks only.
2. **Accessibility / touch targets.** The 44px global rule exists for "healthcare environments."
   We don't delete it — we make it **pointer-aware** so touch devices keep 44px while desktop
   (mouse) gets natural sizing. Verify with `(pointer: coarse)` emulation before shipping.
3. **Typography is opinionated.** `text-sm`→12px touches 1493 sites. **Phase 1 has a hard user
   checkpoint** with screenshots; 13px compromise is the fallback.
4. **`vite build` is stricter than `tsc`.** Run both every phase (per project convention).
5. **Dark mode is the default** — verify every change in dark mode, not just light.
6. **No structural deletes.** This is additive/reversible: token retunes and class swaps. No
   component removal, no route changes, no data changes.
7. **Deploy discipline.** Use `./deploy.sh --frontend` (not bare `vite build`); update DEVLOG.

---

## 6. Sequencing & effort estimate

| Phase | Files | Effort | Impact | Risk |
|---|---|---|---|---|
| 0 Baseline | — | 0.5h | — | none |
| 1 Token retune | ~4 | 1–2h | ★★★★★ | med (typography) — **user gate** |
| 2 Primitives | ~8 | 2–3h | ★★★★ | low |
| 3 Layout shells | ~6 | 3–4h | ★★★ | med (structural) |
| 4 Nav chrome | ~5 | 1h | ★★ | low |
| 5 Charts/heights | ~10 | 2h | ★★ | low |
| 6 Page sweep | ~89 | 6–10h | ★★ | low (mostly deletions) |
| 7 Verify/ship | — | 2h | — | low |

**Recommended first PR:** Phases 1+2 together (the systemic 85%), behind the Phase 1 user
checkpoint. Phases 3–7 follow as a second PR. Land on `main` directly per project workflow,
small sequential commits (avoid long-lived worktrees per global rules).

---

## 7. Definition of done
- All 89 production pages reviewed; dense boards (RTDC, Analytics, ED) gain ≥20% items/viewport.
- Single type scale (token-aligned); single source of page padding; navbar consumes its token.
- No global `min-width:44px`; touch targets preserved on coarse pointers.
- `tsc` + `vite build` green; dark + light parity; no clipping/overflow regressions.
- Auth flows byte-for-byte functionally identical (density only).
- Deployed via `./deploy.sh --frontend`; DEVLOG updated.
