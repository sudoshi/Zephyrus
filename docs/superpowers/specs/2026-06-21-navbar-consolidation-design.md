# Navigation Consolidation — Single Top Navbar

**Date:** 2026-06-21
**Status:** Approved design (pending spec review)
**Author:** Claude (with Dr. Sanjay Udoshi)

## Problem

Zephyrus currently ships **four overlapping navigation systems**, each maintaining its
own copy of the link inventory:

1. Desktop left **Sidebar** (`resources/js/components/layout/Sidebar.tsx`) — lists the 6
   clinical *domains* (Dashboard, RTDC, Perioperative, Emergency, Improvement, Analytics).
2. **MobileDrawer** (`resources/js/components/layout/MobileDrawer.tsx`) — duplicates the
   sidebar links.
3. Two-tier **TopNavigation** (`resources/js/Components/Navigation/TopNavigation.jsx`) —
   bar 1 = logo + workflow switcher (superuser only) + dark-mode + user menu; bar 2 =
   Analytics / Operations / Predictions dropdowns *filtered by the current workflow*.
4. **CommandPalette** (`resources/js/components/ui/CommandPalette.tsx`, Cmd+K) — a third
   hand-maintained catalog of links.

The app is a matrix: **domains × functions**. The sidebar lists the domain axis; the top
sub-bar lists the function axis filtered by a hidden `currentWorkflow` state. This split is
the core source of confusion, and the four inventories drift out of sync (e.g. the
`DashboardContext` superuser workflow points at routes — `/operations/staffing`,
`/predictions/volume-forecasting`, `/analytics/procedure-analysis` — that do not exist).

## Goal

Consolidate to a **single top navbar** with **grouped mega-menu dropdowns** that link to
the correct, working pages in each section. One source of truth feeds the navbar, the
mobile menu, and the command palette.

## Decisions (locked)

| Decision | Choice |
|----------|--------|
| Top-level axis | **Domain-led, always visible** (no workflow gating) |
| Dropdown style | **Grouped mega-menu** (Operations / Analytics / Predictions columns) |
| Emergency dead links | **Build 9 minimal stub pages** so every link is live |
| Improvement layout | **Grouped** (Diagnose / Improve columns), not a flat list |
| Analytics domain | **Keep as a distinct top-level dropdown** (cross-org shortcut) |
| RTDC Risk + dead superuser routes | **Excluded** from nav (building them is separate scope) |

## Architecture: one config, three consumers

A single typed source of truth drives all navigation:

```
resources/js/config/navigationConfig.ts   (new — named export, no `any`)
```

Shape:

```typescript
export interface NavItem {
  label: string;
  href: string;            // concrete URL path (matches a real route)
  icon: LucideIcon;
  adminOnly?: boolean;
}

export interface NavGroup {
  title: string;           // "Operations" | "Analytics" | "Predictions" | ""
  items: readonly NavItem[];
}

export interface NavDomain {
  key: string;             // "rtdc" | "perioperative" | ...
  label: string;
  dashboardHref: string;   // header link inside the menu, e.g. /dashboard/rtdc
  icon: LucideIcon;
  groups: readonly NavGroup[];
  adminOnly?: boolean;
}

export const NAVIGATION: readonly NavDomain[] = [ ... ];
```

Three renderers read `NAVIGATION`:

- **`TopNavbar.tsx`** (new) — the single desktop bar.
- **`MobileNavMenu.tsx`** (new) — hamburger → accordion of the same tree.
- **`CommandPalette.tsx`** (rebuilt) — flattens `NAVIGATION` into searchable entries
  instead of its own hand-maintained list.

This removes the four-way drift: add a page once, it appears everywhere.

## The single bar (desktop)

```
Zephyrus | Dashboard  RTDC▾  Perioperative▾  Emergency▾  Improvement▾  Analytics▾  [Admin▾]      🔍  ◐  👤
```

- **Left:** Zephyrus logo → `/dashboard`.
- **Center:** domain triggers, always visible. `Admin▾` renders only when
  `auth.is_admin` (already shared by `HandleInertiaRequests`). Items flagged `adminOnly`
  are filtered the same way.
- **Right:** search trigger (opens Cmd+K palette), dark-mode toggle, user menu
  (Profile / User Management [admin] / Logout) — preserved from today's `TopNavigation`.
- **Active state:** the domain whose route prefix matches the current URL is highlighted;
  the active item is marked inside the open panel. Uses Inertia `usePage().url`.
- **Behavior:** hover/click opens the panel; Esc closes; arrow keys move between items;
  focus is trapped while open; click-outside closes. One panel open at a time.

## Mega-menu panel (per domain)

Grouped columns **Operations · Analytics · Predictions**, each preceded by a
"**[Domain] Dashboard**" header link. Verified **live** contents (every href maps to an
existing route + page):

### RTDC
- **Operations:** Bed Tracking (`/rtdc/bed-tracking`), Bed Placement (`/rtdc/bed-placement`),
  Global Huddle (`/rtdc/global-huddle`), Unit Huddle (`/rtdc/unit-huddle`),
  Service Huddle (`/rtdc/service-huddle`), Ancillary Services (`/rtdc/ancillary-services`)
- **Analytics:** Utilization (`/rtdc/analytics/utilization`),
  Performance (`/rtdc/analytics/performance`), Resources (`/rtdc/analytics/resources`),
  Trends (`/rtdc/analytics/trends`)
- **Predictions:** Demand (`/rtdc/predictions/demand`), Resources (`/rtdc/predictions/resources`),
  Discharge (`/rtdc/predictions/discharge`)
- *Risk omitted* — `riskAssessment()` renders `RTDC/Predictions/RiskAssessment` which does
  not exist. (Out of scope to build; excluded from nav.)
- Dashboard header → `/dashboard/rtdc`

### Perioperative
- **Operations:** Room Status (`/operations/room-status`), Block Schedule (`/operations/block-schedule`),
  Case Management (`/operations/cases`)
- **Analytics:** Block Utilization (`/analytics/block-utilization`),
  OR Utilization (`/analytics/or-utilization`), Primetime (`/analytics/primetime-utilization`),
  Room Running (`/analytics/room-running`), Turnover Times (`/analytics/turnover-times`)
- **Predictions:** Forecast (`/predictions/forecast`), Demand (`/predictions/demand`),
  Resources (`/predictions/resources`)
- Dashboard header → `/dashboard/perioperative`

### Emergency
All sub-routes exist in `routes/web.php` + `EDDashboardController` but currently render
Inertia pages that do not exist. **9 stub pages will be created** (see "Emergency stub
pages" below) so every link is live:
- **Operations:** Triage (`/ed/operations/triage`), Treatment (`/ed/operations/treatment`),
  Resources (`/ed/operations/resources`)
- **Analytics:** Wait Time (`/ed/analytics/wait-time`), Flow (`/ed/analytics/flow`),
  Resources (`/ed/analytics/resources`)
- **Predictions:** Arrival (`/ed/predictions/arrival`), Acuity (`/ed/predictions/acuity`),
  Resources (`/ed/predictions/resources`)
- Dashboard header → `/dashboard/emergency`

### Improvement
Grouped by the QI workflow (Improvement pages don't map to the Ops/Analytics/Predictions
axis, so it uses its own two groups):
- **Diagnose:** Bottlenecks (`/improvement/bottlenecks`),
  Process Analysis (`/improvement/process`), Root Cause (`/improvement/root-cause`)
- **Improve:** Active Cycles (`/improvement/active`), PDSA Cycles (`/improvement/pdsa`),
  Library (`/improvement/library`)
- Dashboard header → `/dashboard/improvement`
- *Overview (`/improvement/overview`) omitted* — it redirects to `/dashboard/improvement`,
  which the Dashboard header already covers (no duplicate links).

> The `NavGroup.title` field already supports arbitrary group labels, so Improvement's
> `Diagnose`/`Improve` columns and the clinical domains' `Operations`/`Analytics`/
> `Predictions` columns share the same rendering path.

### Analytics (cross-org)
Flat list — the org-wide entry point into the perioperative analytics pages:
Block Utilization, OR Utilization, Primetime, Room Running, Turnover Times
(`/analytics/*`). Landing → `/analytics`.

### Admin (`adminOnly`)
User Management (`/users`), Auth Providers (`/admin/auth-providers/oidc` — the
Authentik/OIDC provider). Rendered only when `auth.is_admin`.

## Emergency stub pages

Create 9 placeholder Inertia pages matching the existing controller render targets, so the
Emergency menu links resolve. Each is a minimal page using the standard `DashboardLayout`
+ `PageContentLayout` pattern (matching sibling content pages like
`Pages/Improvement/Library.jsx`) with a "Coming soon" card, no new backend data required
(controllers already return the render with no props):

```
resources/js/Pages/ED/Analytics/WaitTime.jsx
resources/js/Pages/ED/Analytics/Flow.jsx
resources/js/Pages/ED/Analytics/Resources.jsx
resources/js/Pages/ED/Operations/Triage.jsx
resources/js/Pages/ED/Operations/Treatment.jsx
resources/js/Pages/ED/Operations/Resources.jsx
resources/js/Pages/ED/Predictions/Arrival.jsx
resources/js/Pages/ED/Predictions/Acuity.jsx
resources/js/Pages/ED/Predictions/Resources.jsx
```

A single shared `EDPlaceholder` component keeps these DRY; each page is a thin wrapper
passing its title. No controller/route changes needed — the routes and methods already
exist.

## Layout integration

**Key finding:** `TopNavigation` is the single nav component, but it is rendered by **two**
layouts, and *every* authenticated page funnels through one of them:

```
RTDCPageLayout  → DashboardLayout ─────┐
DashboardLayout (28 pages) ────────────┼─→ TopNavigation
AnalyticsLayout → AuthenticatedLayout ─┘
AuthenticatedLayout (17 pages) ────────→ TopNavigation (+ Sidebar, MobileDrawer,
                                          CommandPalette, ChangePasswordModal)
```

So `TopNavbar` must replace `TopNavigation` in **both** files. Both take the same
`{ isDarkMode, setIsDarkMode }` props, so `TopNavbar` is a drop-in replacement.

`resources/js/Layouts/AuthenticatedLayout.tsx`:
- **Remove:** `<Sidebar />`, `<MobileDrawer />` + `<MobileDrawerTrigger />`, the
  `marginLeft: sidebarWidth` offset on `.layout-main`, the `useUIStore().sidebarOpen` use,
  the standalone `<CommandPalette />` (now owned by `TopNavbar`), and the `<TopNavigation />`
  usage.
- **Add:** `<TopNavbar isDarkMode setIsDarkMode />` in the sticky top bar slot. Main content
  goes full-width (no sidebar offset).
- **Preserved exactly (auth rules — do not touch):**
  `{mustChangePassword && <ChangePasswordModal />}`, `DarkModeContext` provider +
  `useDarkMode`, Google Fonts injection, skip-to-content link.

`resources/js/Components/Dashboard/DashboardLayout.jsx`:
- **Replace** `<TopNavigation .../>` with `<TopNavbar .../>` (same props). No other change —
  it never had a sidebar.

**CommandPalette ownership:** `TopNavbar` mounts `<CommandPalette />` itself, so the palette
+ the search button + Cmd+K work on *every* page. (Today the palette is only in
`AuthenticatedLayout`, so Cmd+K is silently dead on the 28 `DashboardLayout` pages — this
fixes that.) The palette mounts exactly once per page because each page renders exactly one
layout, which renders one `TopNavbar`.

## Removed / deprecated

- **Delete:** `components/layout/Sidebar.tsx`, `components/layout/MobileDrawer.tsx`,
  `Components/Navigation/TopNavigation.jsx` (replaced by `TopNavbar`).
- **Delete if unreferenced after sweep:** `Components/NavLink.jsx`,
  `Components/ResponsiveNavLink.jsx` (legacy).
- **Keep, demote from nav-driver:** `Contexts/DashboardContext.tsx` and the
  `/set-preference/{workflow}` route remain (Home.jsx still renders per workflow), but
  `currentWorkflow` no longer gates navigation. The `workflowNavigationConfig` block that
  fed the sub-bar is removed once `TopNavbar` no longer reads it.
- **Excluded from the navbar (intentionally):** `/design/*` dev pages, the `Examples/*`
  demos, and legacy root duplicates (`Welcome.jsx`, root `BlockSchedule.jsx`/`Cases.jsx`/
  `RoomStatus.jsx`). Left on disk; simply not surfaced.
- **`uiStore.sidebarOpen`:** remove if only the sidebar consumed it (verify during impl).

## Components to build

| File | Responsibility |
|------|----------------|
| `config/navigationConfig.ts` | Typed `NAVIGATION` tree (single source of truth) |
| `Components/Navigation/TopNavbar.tsx` | The single desktop bar: logo, domain triggers, right-side actions, embeds mobile menu |
| `Components/Navigation/NavMegaMenu.tsx` | One domain's dropdown panel (grouped columns); keyboard + focus handling |
| `Components/Navigation/MobileNavMenu.tsx` | Hamburger + accordion rendering of `NAVIGATION` < 1024px |
| `Components/Navigation/UserMenu.tsx` | Extract user dropdown (Profile / Users[admin] / Logout) from old TopNavigation |
| `Pages/ED/_EDPlaceholder.jsx` (+ 9 thin pages) | Emergency stub pages |

## Quality / constraints

- **TS:** strict, no `any`, named exports (per global rules). New components in `.tsx`.
  Stub ED pages may be `.jsx` to match the existing `Pages/` convention.
- **Icons:** lucide-react (matches the current Sidebar) — replace the heroicons strings the
  old TopNavigation used.
- **Styling:** existing dark clinical CSS variables (`--surface-raised`, `--border-subtle`,
  `--text-primary`, etc.) and Tailwind tokens. No new color system.
- **Accessibility:** `role="menu"`/`menuitem`, `aria-expanded`, Esc to close, arrow-key
  navigation, focus trap while a panel is open, visible focus rings, the existing
  skip-to-content link still works.
- **Responsive:** desktop bar ≥ 1024px; hamburger + accordion < 1024px. No horizontal
  scroll on the bar — if domain triggers overflow, collapse overflow into a "More" menu.
- **Verification:** `npx tsc --noEmit` **and** `npx vite build` (vite is stricter) must pass
  before completion; manually click every nav link to confirm no Inertia 404s.

## Out of scope

- Building real Emergency analytics/operations/predictions functionality (stubs only).
- Building RTDC Risk Assessment page.
- Any change to authentication components or flows (protected by
  `.claude/rules/auth-system.md`).
- Backend route/controller changes (all target routes already exist).

## Success criteria

1. A single top navbar is the only persistent navigation chrome; no sidebar, no two-tier
   sub-bar.
2. Every domain is reachable at all times via its dropdown; every link resolves to a
   working page (zero Inertia 404s).
3. Navbar, mobile menu, and command palette all derive from `navigationConfig.ts`.
4. Auth flow (temp password → change-password modal) and dark mode are unchanged.
5. `tsc --noEmit` and `vite build` are green.
