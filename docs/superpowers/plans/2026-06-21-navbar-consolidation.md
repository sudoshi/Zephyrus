# Navbar Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Zephyrus's four overlapping navigation systems (sidebar, mobile drawer, two-tier top nav, command palette) with a single domain-led top navbar whose grouped mega-menu dropdowns, mobile menu, and command palette all derive from one typed config.

**Architecture:** A new `navigationConfig.ts` is the single source of truth (`domains → groups → items`). A new `TopNavbar` composes per-domain `NavMegaMenu` dropdowns (headlessui `Popover` + a pure `MegaMenuPanel`), a `UserMenu`, a search button, a dark-mode toggle, a `MobileNavMenu`, and mounts the `CommandPalette`. `TopNavbar` is a drop-in replacement for `TopNavigation` (same `{ isDarkMode, setIsDarkMode }` props) in **both** `DashboardLayout.jsx` and `AuthenticatedLayout.tsx` — the two layouts every authenticated page funnels through. The sidebar/drawer are deleted; 9 Emergency stub pages are added so every link resolves.

**Tech Stack:** React 19 + TypeScript (strict, named exports, no `any`), Inertia.js v2, headlessui v2 (`Popover`, `Menu`, `Transition`), lucide-react icons, cmdk, Zustand (`uiStore`), TailwindCSS (`healthcare-*` tokens + CSS vars), Vitest + @testing-library/react.

**Reference spec:** `docs/superpowers/specs/2026-06-21-navbar-consolidation-design.md`

**Branch:** `feature/navbar-consolidation` (already created).

**Conventions discovered (do not deviate):**
- Tests live in `tests/js/**/*.test.{ts,tsx}`; run with `npm test` (= `vitest run`) or `npx vitest run <path>`.
- `tests/js/setup.ts` globally mocks `@inertiajs/react`: `router.*` are `vi.fn()`, `usePage` returns `{ props: { auth: { user: null }, flash: {} } }` (NO `url`), `Link` renders `<a>`, `Head` renders `null`. Override per-test with `vi.mocked(usePage).mockReturnValue(...)`.
- Path alias `@` → `resources/js`.
- Existing dropdown styling uses `bg-healthcare-surface dark:bg-healthcare-surface-dark`, `border-healthcare-border dark:border-healthcare-border-dark`, `text-healthcare-text-primary/secondary dark:*-dark`, `hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark`. Reuse these so the new nav matches.
- Reusable components that already exist: `@/Components/UserAvatar`, `@/Components/Common/DarkModeToggle`, `@/components/ui/CommandPalette`.

---

## File Structure

**Create:**
- `resources/js/config/navigationConfig.ts` — typed `NAVIGATION` tree + helpers (`isDomainActive`, `visibleDomains`, `flattenNavigation`, `TOP_LEVEL_DASHBOARD`).
- `resources/js/Components/Navigation/UserMenu.tsx` — user dropdown + pure `getUserMenuItems(isAdmin)`.
- `resources/js/Components/Navigation/MegaMenuPanel.tsx` — pure dropdown-panel renderer for one domain.
- `resources/js/Components/Navigation/NavMegaMenu.tsx` — headlessui `Popover` wrapper (trigger + panel).
- `resources/js/Components/Navigation/MobileNavMenu.tsx` — hamburger + accordion (plain state, fully testable).
- `resources/js/Components/Navigation/TopNavbar.tsx` — the single bar; composes the above + mounts `CommandPalette`.
- `resources/js/Components/ED/EDPlaceholder.jsx` — shared "coming soon" page body.
- `resources/js/Pages/ED/Analytics/{WaitTime,Flow,Resources}.jsx`
- `resources/js/Pages/ED/Operations/{Triage,Treatment,Resources}.jsx`
- `resources/js/Pages/ED/Predictions/{Arrival,Acuity,Resources}.jsx`
- Tests: `tests/js/config/navigationConfig.test.ts`, `tests/js/components/UserMenu.test.tsx`, `tests/js/components/MegaMenuPanel.test.tsx`, `tests/js/components/NavMegaMenu.test.tsx`, `tests/js/components/MobileNavMenu.test.tsx`, `tests/js/components/TopNavbar.test.tsx`.

**Modify:**
- `resources/js/types/index.ts` — extend `PageProps.auth` with `is_admin?` + `roles?`.
- `resources/js/components/ui/CommandPalette.tsx` — derive items from `navigationConfig`.
- `resources/js/Components/Dashboard/DashboardLayout.jsx` — `TopNavigation` → `TopNavbar`.
- `resources/js/Layouts/AuthenticatedLayout.tsx` — remove sidebar/drawer/standalone palette; use `TopNavbar`; full-width content.
- `resources/js/stores/uiStore.ts` — remove `sidebarOpen`/`toggleSidebar` if unused after deletions.
- `tests/js/components/CommandPalette.test.tsx` — add one assertion for a config-driven sub-page.

**Delete (after grep confirms no remaining imports):**
- `resources/js/components/layout/Sidebar.tsx`
- `resources/js/components/layout/MobileDrawer.tsx`
- `resources/js/Components/Navigation/TopNavigation.jsx`
- `resources/js/Components/NavLink.jsx`, `resources/js/Components/ResponsiveNavLink.jsx` (only if unreferenced)

---

## Task 1: Extend `PageProps.auth` type

The shared Inertia props include `auth.is_admin` and `auth.roles` (see `HandleInertiaRequests.php`), but `PageProps` in TS only declares `auth.user`. Add them so the navbar can read `is_admin` type-safely.

**Files:**
- Modify: `resources/js/types/index.ts:11-20`

- [ ] **Step 1: Update the `PageProps` interface**

Replace the existing `PageProps` interface (lines 11-20) with:

```ts
export interface PageProps {
  auth: {
    user: User | null;
    roles?: string[];
    is_admin?: boolean;
  };
  flash?: {
    message?: string;
    error?: string;
  };
  [key: string]: unknown;
}
```

- [ ] **Step 2: Verify types still compile**

Run: `npx tsc --noEmit`
Expected: no new errors referencing `resources/js/types/index.ts` (pre-existing errors elsewhere, if any, are unchanged).

- [ ] **Step 3: Commit**

```bash
git add resources/js/types/index.ts
git commit -m "feat(nav): add is_admin/roles to PageProps.auth type"
```

---

## Task 2: Navigation config (single source of truth)

**Files:**
- Create: `resources/js/config/navigationConfig.ts`
- Test: `tests/js/config/navigationConfig.test.ts`

- [ ] **Step 1: Write the failing test**

Create `tests/js/config/navigationConfig.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import {
  NAVIGATION,
  TOP_LEVEL_DASHBOARD,
  isDomainActive,
  visibleDomains,
  flattenNavigation,
} from '@/config/navigationConfig';

describe('navigationConfig', () => {
  it('exposes the six dropdown domains in order', () => {
    expect(NAVIGATION.map((d) => d.key)).toEqual([
      'rtdc',
      'perioperative',
      'emergency',
      'improvement',
      'analytics',
      'admin',
    ]);
  });

  it('only the admin domain is adminOnly', () => {
    const adminOnly = NAVIGATION.filter((d) => d.adminOnly).map((d) => d.key);
    expect(adminOnly).toEqual(['admin']);
  });

  it('every leaf href is an absolute path', () => {
    for (const domain of NAVIGATION) {
      for (const group of domain.groups) {
        for (const item of group.items) {
          expect(item.href.startsWith('/')).toBe(true);
        }
      }
    }
  });

  it('does not link the known-dead routes', () => {
    const hrefs = NAVIGATION.flatMap((d) => d.groups.flatMap((g) => g.items.map((i) => i.href)));
    expect(hrefs).not.toContain('/rtdc/predictions/risk');
    expect(hrefs).not.toContain('/operations/staffing');
    expect(hrefs).not.toContain('/analytics/procedure-analysis');
    expect(hrefs).not.toContain('/predictions/volume-forecasting');
  });

  it('matches a domain by URL prefix without cross-domain bleed', () => {
    const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;
    const analytics = NAVIGATION.find((d) => d.key === 'analytics')!;
    const perioperative = NAVIGATION.find((d) => d.key === 'perioperative')!;

    expect(isDomainActive(rtdc, '/rtdc/bed-tracking')).toBe(true);
    expect(isDomainActive(rtdc, '/dashboard/rtdc')).toBe(true);
    expect(isDomainActive(analytics, '/analytics/or-utilization')).toBe(true);
    // /analytics belongs to the Analytics domain, NOT Perioperative
    expect(isDomainActive(perioperative, '/analytics/or-utilization')).toBe(false);
    expect(isDomainActive(rtdc, '/analytics/or-utilization')).toBe(false);
  });

  it('hides the admin domain for non-admins', () => {
    expect(visibleDomains(false).map((d) => d.key)).not.toContain('admin');
    expect(visibleDomains(true).map((d) => d.key)).toContain('admin');
  });

  it('flattens to command-palette entries and drops admin items for non-admins', () => {
    const adminFlat = flattenNavigation(true);
    const userFlat = flattenNavigation(false);
    expect(adminFlat.some((e) => e.href === '/users')).toBe(true);
    expect(userFlat.some((e) => e.href === '/users')).toBe(false);
    // Sub-pages are present and grouped by "Domain Group"
    expect(userFlat.some((e) => e.label === 'Bed Tracking' && e.group === 'RTDC Operations')).toBe(true);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/config/navigationConfig.test.ts`
Expected: FAIL — cannot resolve `@/config/navigationConfig`.

- [ ] **Step 3: Create the config**

Create `resources/js/config/navigationConfig.ts`:

```ts
import type { LucideIcon } from 'lucide-react';
import {
  Activity,
  AlertCircle,
  Ambulance,
  ArrowRightCircle,
  BarChart3,
  BedDouble,
  BookOpen,
  Boxes,
  CalendarClock,
  ClipboardList,
  Clock,
  DoorOpen,
  Gauge,
  GitBranch,
  HeartPulse,
  KeyRound,
  LineChart,
  ListChecks,
  PieChart,
  RefreshCcw,
  Search,
  Shield,
  Siren,
  Stethoscope,
  Timer,
  TrendingUp,
  Users,
  Workflow,
} from 'lucide-react';

export interface NavLeaf {
  readonly label: string;
  readonly href: string;
  readonly icon: LucideIcon;
  readonly adminOnly?: boolean;
}

export interface NavGroup {
  /** Column heading; empty string = ungrouped flat list. */
  readonly title: string;
  readonly items: readonly NavLeaf[];
}

export interface NavDomain {
  readonly key: string;
  readonly label: string;
  readonly icon: LucideIcon;
  /** Optional header link rendered at the top of the panel. */
  readonly dashboardHref?: string;
  readonly dashboardLabel?: string;
  /** URL path prefixes that mark this domain active. */
  readonly matchPrefixes: readonly string[];
  readonly groups: readonly NavGroup[];
  readonly adminOnly?: boolean;
}

export const TOP_LEVEL_DASHBOARD = { label: 'Dashboard', href: '/dashboard' } as const;

export const NAVIGATION: readonly NavDomain[] = [
  {
    key: 'rtdc',
    label: 'RTDC',
    icon: BedDouble,
    dashboardHref: '/dashboard/rtdc',
    dashboardLabel: 'RTDC Dashboard',
    matchPrefixes: ['/rtdc', '/dashboard/rtdc'],
    groups: [
      {
        title: 'Operations',
        items: [
          { label: 'Bed Tracking', href: '/rtdc/bed-tracking', icon: Activity },
          { label: 'Bed Placement', href: '/rtdc/bed-placement', icon: ClipboardList },
          { label: 'Global Huddle', href: '/rtdc/global-huddle', icon: Users },
          { label: 'Unit Huddle', href: '/rtdc/unit-huddle', icon: Users },
          { label: 'Service Huddle', href: '/rtdc/service-huddle', icon: Users },
          { label: 'Ancillary Services', href: '/rtdc/ancillary-services', icon: Boxes },
        ],
      },
      {
        title: 'Analytics',
        items: [
          { label: 'Utilization', href: '/rtdc/analytics/utilization', icon: Gauge },
          { label: 'Performance', href: '/rtdc/analytics/performance', icon: LineChart },
          { label: 'Resources', href: '/rtdc/analytics/resources', icon: Boxes },
          { label: 'Trends', href: '/rtdc/analytics/trends', icon: TrendingUp },
        ],
      },
      {
        title: 'Predictions',
        items: [
          { label: 'Demand', href: '/rtdc/predictions/demand', icon: PieChart },
          { label: 'Resources', href: '/rtdc/predictions/resources', icon: Boxes },
          { label: 'Discharge', href: '/rtdc/predictions/discharge', icon: ArrowRightCircle },
        ],
      },
    ],
  },
  {
    key: 'perioperative',
    label: 'Perioperative',
    icon: Stethoscope,
    dashboardHref: '/dashboard/perioperative',
    dashboardLabel: 'Perioperative Dashboard',
    matchPrefixes: ['/operations', '/predictions', '/dashboard/perioperative'],
    groups: [
      {
        title: 'Operations',
        items: [
          { label: 'Room Status', href: '/operations/room-status', icon: DoorOpen },
          { label: 'Block Schedule', href: '/operations/block-schedule', icon: CalendarClock },
          { label: 'Case Management', href: '/operations/cases', icon: ClipboardList },
        ],
      },
      {
        title: 'Analytics',
        items: [
          { label: 'Block Utilization', href: '/analytics/block-utilization', icon: BarChart3 },
          { label: 'OR Utilization', href: '/analytics/or-utilization', icon: Gauge },
          { label: 'Primetime Utilization', href: '/analytics/primetime-utilization', icon: Clock },
          { label: 'Room Running', href: '/analytics/room-running', icon: Activity },
          { label: 'Turnover Times', href: '/analytics/turnover-times', icon: Timer },
        ],
      },
      {
        title: 'Predictions',
        items: [
          { label: 'Utilization Forecast', href: '/predictions/forecast', icon: LineChart },
          { label: 'Demand Analysis', href: '/predictions/demand', icon: PieChart },
          { label: 'Resource Planning', href: '/predictions/resources', icon: Boxes },
        ],
      },
    ],
  },
  {
    key: 'emergency',
    label: 'Emergency',
    icon: Siren,
    dashboardHref: '/dashboard/emergency',
    dashboardLabel: 'Emergency Dashboard',
    matchPrefixes: ['/ed', '/dashboard/emergency'],
    groups: [
      {
        title: 'Operations',
        items: [
          { label: 'Triage', href: '/ed/operations/triage', icon: ListChecks },
          { label: 'Treatment', href: '/ed/operations/treatment', icon: HeartPulse },
          { label: 'Resources', href: '/ed/operations/resources', icon: Boxes },
        ],
      },
      {
        title: 'Analytics',
        items: [
          { label: 'Wait Time', href: '/ed/analytics/wait-time', icon: Clock },
          { label: 'Patient Flow', href: '/ed/analytics/flow', icon: Workflow },
          { label: 'Resources', href: '/ed/analytics/resources', icon: Boxes },
        ],
      },
      {
        title: 'Predictions',
        items: [
          { label: 'Arrival', href: '/ed/predictions/arrival', icon: Ambulance },
          { label: 'Acuity', href: '/ed/predictions/acuity', icon: Activity },
          { label: 'Resources', href: '/ed/predictions/resources', icon: Boxes },
        ],
      },
    ],
  },
  {
    key: 'improvement',
    label: 'Improvement',
    icon: TrendingUp,
    dashboardHref: '/dashboard/improvement',
    dashboardLabel: 'Improvement Dashboard',
    matchPrefixes: ['/improvement', '/dashboard/improvement'],
    groups: [
      {
        title: 'Diagnose',
        items: [
          { label: 'Bottlenecks', href: '/improvement/bottlenecks', icon: AlertCircle },
          { label: 'Process Analysis', href: '/improvement/process', icon: GitBranch },
          { label: 'Root Cause', href: '/improvement/root-cause', icon: Search },
        ],
      },
      {
        title: 'Improve',
        items: [
          { label: 'Active Cycles', href: '/improvement/active', icon: RefreshCcw },
          { label: 'PDSA Cycles', href: '/improvement/pdsa', icon: RefreshCcw },
          { label: 'Library', href: '/improvement/library', icon: BookOpen },
        ],
      },
    ],
  },
  {
    key: 'analytics',
    label: 'Analytics',
    icon: BarChart3,
    dashboardHref: '/analytics',
    dashboardLabel: 'Analytics Overview',
    matchPrefixes: ['/analytics'],
    groups: [
      {
        title: '',
        items: [
          { label: 'Block Utilization', href: '/analytics/block-utilization', icon: BarChart3 },
          { label: 'OR Utilization', href: '/analytics/or-utilization', icon: Gauge },
          { label: 'Primetime Utilization', href: '/analytics/primetime-utilization', icon: Clock },
          { label: 'Room Running', href: '/analytics/room-running', icon: Activity },
          { label: 'Turnover Times', href: '/analytics/turnover-times', icon: Timer },
        ],
      },
    ],
  },
  {
    key: 'admin',
    label: 'Admin',
    icon: Shield,
    adminOnly: true,
    matchPrefixes: ['/users', '/admin'],
    groups: [
      {
        title: '',
        items: [
          { label: 'User Management', href: '/users', icon: Users, adminOnly: true },
          { label: 'Auth Providers', href: '/admin/auth-providers/oidc', icon: KeyRound, adminOnly: true },
        ],
      },
    ],
  },
];

export function isDomainActive(domain: NavDomain, url: string): boolean {
  const path = (url || '').split('?')[0];
  return domain.matchPrefixes.some((p) => path === p || path.startsWith(`${p}/`));
}

export function visibleDomains(isAdmin: boolean): readonly NavDomain[] {
  return NAVIGATION.filter((d) => !d.adminOnly || isAdmin);
}

export interface FlatNavEntry {
  readonly label: string;
  readonly href: string;
  readonly group: string;
}

/** Flatten the config for the command palette, respecting admin gating. */
export function flattenNavigation(isAdmin: boolean): FlatNavEntry[] {
  const entries: FlatNavEntry[] = [
    { label: TOP_LEVEL_DASHBOARD.label, href: TOP_LEVEL_DASHBOARD.href, group: 'Navigation' },
  ];

  for (const domain of visibleDomains(isAdmin)) {
    if (domain.dashboardHref) {
      entries.push({ label: domain.label, href: domain.dashboardHref, group: 'Navigation' });
    }
    for (const group of domain.groups) {
      const groupLabel = group.title ? `${domain.label} ${group.title}` : domain.label;
      for (const item of group.items) {
        if (item.adminOnly && !isAdmin) continue;
        entries.push({ label: item.label, href: item.href, group: groupLabel });
      }
    }
  }

  return entries;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/config/navigationConfig.test.ts`
Expected: PASS (all assertions).

- [ ] **Step 5: Commit**

```bash
git add resources/js/config/navigationConfig.ts tests/js/config/navigationConfig.test.ts
git commit -m "feat(nav): typed navigationConfig single source of truth"
```

---

## Task 3: UserMenu

**Files:**
- Create: `resources/js/Components/Navigation/UserMenu.tsx`
- Test: `tests/js/components/UserMenu.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/js/components/UserMenu.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { getUserMenuItems } from '@/Components/Navigation/UserMenu';

describe('getUserMenuItems', () => {
  it('shows Profile and Logout for a normal user, no User Management', () => {
    const labels = getUserMenuItems(false).map((i) => i.label);
    expect(labels).toEqual(['Profile', 'Logout']);
  });

  it('includes User Management for an admin', () => {
    const labels = getUserMenuItems(true).map((i) => i.label);
    expect(labels).toEqual(['Profile', 'User Management', 'Logout']);
  });

  it('marks Logout as an action, not a link', () => {
    const logout = getUserMenuItems(false).find((i) => i.label === 'Logout')!;
    expect(logout.action).toBe('logout');
    expect(logout.href).toBeUndefined();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/components/UserMenu.test.tsx`
Expected: FAIL — cannot resolve `@/Components/Navigation/UserMenu`.

- [ ] **Step 3: Create the component**

Create `resources/js/Components/Navigation/UserMenu.tsx`:

```tsx
import { Fragment } from 'react';
import { Link, router } from '@inertiajs/react';
import { Menu, Transition } from '@headlessui/react';
import { ChevronDown, LogOut, User, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import UserAvatar from '@/Components/UserAvatar';

export interface UserMenuItem {
  readonly label: string;
  readonly icon: LucideIcon;
  readonly href?: string;
  readonly action?: 'logout';
}

export function getUserMenuItems(isAdmin: boolean): UserMenuItem[] {
  const items: UserMenuItem[] = [{ label: 'Profile', icon: User, href: '/profile' }];
  if (isAdmin) {
    items.push({ label: 'User Management', icon: Users, href: '/users' });
  }
  items.push({ label: 'Logout', icon: LogOut, action: 'logout' });
  return items;
}

interface UserMenuProps {
  isAdmin: boolean;
}

export function UserMenu({ isAdmin }: UserMenuProps) {
  const items = getUserMenuItems(isAdmin);

  return (
    <Menu as="div" className="relative z-[75]">
      <Menu.Button className="flex items-center space-x-2 rounded-md border border-transparent p-2 transition-all duration-300 hover:border-healthcare-border hover:bg-healthcare-hover dark:hover:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark">
        <UserAvatar />
        <ChevronDown className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
      </Menu.Button>
      <Transition
        as={Fragment}
        enter="transition ease-out duration-100"
        enterFrom="transform opacity-0 scale-95"
        enterTo="transform opacity-100 scale-100"
        leave="transition ease-in duration-75"
        leaveFrom="transform opacity-100 scale-100"
        leaveTo="transform opacity-0 scale-95"
      >
        <Menu.Items className="absolute right-0 z-[70] mt-2 w-48 rounded-lg border border-healthcare-border bg-healthcare-surface py-1 shadow-lg dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          {items.map((item) => (
            <Menu.Item key={item.label}>
              {({ active }) => {
                const Icon = item.icon;
                const className = `flex w-full items-center px-4 py-2.5 text-sm transition-all duration-300 ${
                  active
                    ? 'bg-healthcare-hover text-healthcare-text-primary dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
                    : 'text-healthcare-text-secondary hover:bg-healthcare-hover/50 dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark/50'
                }`;
                if (item.action === 'logout') {
                  return (
                    <button type="button" onClick={() => router.post('/logout')} className={className}>
                      <Icon className="mr-2 h-4 w-4" />
                      {item.label}
                    </button>
                  );
                }
                return (
                  <Link href={item.href as string} className={className}>
                    <Icon className="mr-2 h-4 w-4" />
                    {item.label}
                  </Link>
                );
              }}
            </Menu.Item>
          ))}
        </Menu.Items>
      </Transition>
    </Menu>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/components/UserMenu.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Navigation/UserMenu.tsx tests/js/components/UserMenu.test.tsx
git commit -m "feat(nav): UserMenu with admin-gated User Management"
```

---

## Task 4: MegaMenuPanel (pure panel)

**Files:**
- Create: `resources/js/Components/Navigation/MegaMenuPanel.tsx`
- Test: `tests/js/components/MegaMenuPanel.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/js/components/MegaMenuPanel.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { MegaMenuPanel } from '@/Components/Navigation/MegaMenuPanel';
import { NAVIGATION } from '@/config/navigationConfig';

const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;
const admin = NAVIGATION.find((d) => d.key === 'admin')!;

describe('MegaMenuPanel', () => {
  it('renders the dashboard header link and every group title', () => {
    render(<MegaMenuPanel domain={rtdc} isAdmin={false} />);
    expect(screen.getByText('RTDC Dashboard')).toBeInTheDocument();
    expect(screen.getByText('Operations')).toBeInTheDocument();
    expect(screen.getByText('Analytics')).toBeInTheDocument();
    expect(screen.getByText('Predictions')).toBeInTheDocument();
  });

  it('renders each item as a link with the correct href', () => {
    render(<MegaMenuPanel domain={rtdc} isAdmin={false} />);
    const bedTracking = screen.getByText('Bed Tracking').closest('a');
    expect(bedTracking).toHaveAttribute('href', '/rtdc/bed-tracking');
    expect(screen.getByText('Discharge').closest('a')).toHaveAttribute('href', '/rtdc/predictions/discharge');
  });

  it('hides admin-only items when not an admin', () => {
    render(<MegaMenuPanel domain={admin} isAdmin={false} />);
    expect(screen.queryByText('User Management')).not.toBeInTheDocument();
  });

  it('shows admin-only items for an admin', () => {
    render(<MegaMenuPanel domain={admin} isAdmin />);
    expect(screen.getByText('User Management').closest('a')).toHaveAttribute('href', '/users');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/components/MegaMenuPanel.test.tsx`
Expected: FAIL — cannot resolve `@/Components/Navigation/MegaMenuPanel`.

- [ ] **Step 3: Create the component**

Create `resources/js/Components/Navigation/MegaMenuPanel.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import type { NavDomain } from '@/config/navigationConfig';

interface MegaMenuPanelProps {
  domain: NavDomain;
  isAdmin: boolean;
  onNavigate?: () => void;
}

export function MegaMenuPanel({ domain, isAdmin, onNavigate }: MegaMenuPanelProps) {
  return (
    <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-xl dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      {domain.dashboardHref && (
        <Link
          href={domain.dashboardHref}
          onClick={onNavigate}
          className="mb-3 block border-b border-healthcare-border pb-2 text-sm font-semibold text-healthcare-text-primary hover:text-healthcare-primary dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:text-healthcare-primary-dark"
        >
          {domain.dashboardLabel}
        </Link>
      )}
      <div className="flex gap-6">
        {domain.groups.map((group) => {
          const items = group.items.filter((item) => !item.adminOnly || isAdmin);
          if (items.length === 0) return null;
          return (
            <div key={group.title || domain.key} className="min-w-[180px]">
              {group.title && (
                <div className="mb-1 px-2 text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {group.title}
                </div>
              )}
              <ul className="space-y-0.5">
                {items.map((item) => {
                  const Icon = item.icon;
                  return (
                    <li key={item.href}>
                      <Link
                        href={item.href}
                        onClick={onNavigate}
                        className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-healthcare-text-secondary transition-colors duration-200 hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark"
                      >
                        <Icon className="h-4 w-4 flex-shrink-0" />
                        <span>{item.label}</span>
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </div>
          );
        })}
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/components/MegaMenuPanel.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Navigation/MegaMenuPanel.tsx tests/js/components/MegaMenuPanel.test.tsx
git commit -m "feat(nav): MegaMenuPanel grouped dropdown renderer"
```

---

## Task 5: NavMegaMenu (Popover wrapper)

**Files:**
- Create: `resources/js/Components/Navigation/NavMegaMenu.tsx`
- Test: `tests/js/components/NavMegaMenu.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/js/components/NavMegaMenu.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { NavMegaMenu } from '@/Components/Navigation/NavMegaMenu';
import { NAVIGATION } from '@/config/navigationConfig';

const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;

describe('NavMegaMenu', () => {
  it('renders the domain trigger label', () => {
    render(<NavMegaMenu domain={rtdc} isAdmin={false} active={false} />);
    expect(screen.getByRole('button', { name: /RTDC/i })).toBeInTheDocument();
  });

  it('marks the trigger active via aria-current when active', () => {
    render(<NavMegaMenu domain={rtdc} isAdmin={false} active />);
    expect(screen.getByRole('button', { name: /RTDC/i })).toHaveAttribute('aria-current', 'page');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/components/NavMegaMenu.test.tsx`
Expected: FAIL — cannot resolve `@/Components/Navigation/NavMegaMenu`.

- [ ] **Step 3: Create the component**

Create `resources/js/Components/Navigation/NavMegaMenu.tsx`:

```tsx
import { Fragment } from 'react';
import { Popover, Transition } from '@headlessui/react';
import { ChevronDown } from 'lucide-react';
import type { NavDomain } from '@/config/navigationConfig';
import { MegaMenuPanel } from './MegaMenuPanel';

interface NavMegaMenuProps {
  domain: NavDomain;
  isAdmin: boolean;
  active: boolean;
}

export function NavMegaMenu({ domain, isAdmin, active }: NavMegaMenuProps) {
  const Icon = domain.icon;
  return (
    <Popover className="relative">
      <Popover.Button
        aria-current={active ? 'page' : undefined}
        className={`flex items-center gap-1.5 rounded-md border border-transparent px-3 py-2 text-sm font-medium transition-all duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
          active
            ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-primary-dark'
            : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
        }`}
      >
        <Icon className="h-4 w-4" />
        <span>{domain.label}</span>
        <ChevronDown className="h-3.5 w-3.5" />
      </Popover.Button>
      <Transition
        as={Fragment}
        enter="transition ease-out duration-100"
        enterFrom="transform opacity-0 scale-95"
        enterTo="transform opacity-100 scale-100"
        leave="transition ease-in duration-75"
        leaveFrom="transform opacity-100 scale-100"
        leaveTo="transform opacity-0 scale-95"
      >
        <Popover.Panel className="absolute left-0 z-[70] mt-2">
          {({ close }) => <MegaMenuPanel domain={domain} isAdmin={isAdmin} onNavigate={close} />}
        </Popover.Panel>
      </Transition>
    </Popover>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/components/NavMegaMenu.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Navigation/NavMegaMenu.tsx tests/js/components/NavMegaMenu.test.tsx
git commit -m "feat(nav): NavMegaMenu Popover wrapper with active state"
```

---

## Task 6: MobileNavMenu (hamburger + accordion)

Plain React state (no headlessui Dialog) so it is fully deterministic in jsdom.

**Files:**
- Create: `resources/js/Components/Navigation/MobileNavMenu.tsx`
- Test: `tests/js/components/MobileNavMenu.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/js/components/MobileNavMenu.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { MobileNavMenu } from '@/Components/Navigation/MobileNavMenu';

describe('MobileNavMenu', () => {
  it('is closed initially — panel domains not shown', () => {
    render(<MobileNavMenu isAdmin={false} url="" />);
    expect(screen.queryByRole('link', { name: /RTDC Dashboard/i })).not.toBeInTheDocument();
  });

  it('opens on hamburger click and lists domains', () => {
    render(<MobileNavMenu isAdmin={false} url="" />);
    fireEvent.click(screen.getByRole('button', { name: /open menu/i }));
    expect(screen.getByText('RTDC')).toBeInTheDocument();
    expect(screen.getByText('Perioperative')).toBeInTheDocument();
    expect(screen.getByText('Improvement')).toBeInTheDocument();
  });

  it('hides Admin for non-admins and shows it for admins', () => {
    const { rerender } = render(<MobileNavMenu isAdmin={false} url="" />);
    fireEvent.click(screen.getByRole('button', { name: /open menu/i }));
    expect(screen.queryByText('Admin')).not.toBeInTheDocument();

    rerender(<MobileNavMenu isAdmin url="" />);
    fireEvent.click(screen.getByRole('button', { name: /open menu/i }));
    expect(screen.getByText('Admin')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/components/MobileNavMenu.test.tsx`
Expected: FAIL — cannot resolve `@/Components/Navigation/MobileNavMenu`.

- [ ] **Step 3: Create the component**

Create `resources/js/Components/Navigation/MobileNavMenu.tsx`:

```tsx
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Menu as MenuIcon, X, ChevronDown } from 'lucide-react';
import { TOP_LEVEL_DASHBOARD, visibleDomains, isDomainActive } from '@/config/navigationConfig';
import type { NavDomain } from '@/config/navigationConfig';

interface MobileNavMenuProps {
  isAdmin: boolean;
  url: string;
}

export function MobileNavMenu({ isAdmin, url }: MobileNavMenuProps) {
  const [open, setOpen] = useState(false);
  const [expanded, setExpanded] = useState<string | null>(null);
  const domains = visibleDomains(isAdmin);

  const close = () => {
    setOpen(false);
    setExpanded(null);
  };

  return (
    <div className="lg:hidden">
      <button
        type="button"
        aria-label="Open menu"
        aria-expanded={open}
        onClick={() => setOpen(true)}
        className="rounded-md p-2 text-healthcare-text-primary hover:bg-healthcare-hover dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
      >
        <MenuIcon className="h-6 w-6" />
      </button>

      {open && (
        <div className="fixed inset-0 z-[90]">
          <div className="fixed inset-0 bg-black/50" onClick={close} aria-hidden="true" />
          <div className="fixed inset-y-0 left-0 flex w-80 max-w-[85%] flex-col overflow-y-auto border-r border-healthcare-border bg-healthcare-surface shadow-xl dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex items-center justify-between border-b border-healthcare-border px-4 py-3 dark:border-healthcare-border-dark">
              <span className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Zephyrus
              </span>
              <button
                type="button"
                aria-label="Close menu"
                onClick={close}
                className="rounded-md p-1.5 text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark"
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            <nav className="flex-1 px-2 py-3">
              <Link
                href={TOP_LEVEL_DASHBOARD.href}
                onClick={close}
                className="block rounded-md px-3 py-2 text-sm font-medium text-healthcare-text-primary hover:bg-healthcare-hover dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
              >
                {TOP_LEVEL_DASHBOARD.label}
              </Link>

              {domains.map((domain: NavDomain) => {
                const isOpen = expanded === domain.key;
                const active = isDomainActive(domain, url);
                const Icon = domain.icon;
                return (
                  <div key={domain.key} className="mt-0.5">
                    <button
                      type="button"
                      onClick={() => setExpanded(isOpen ? null : domain.key)}
                      aria-expanded={isOpen}
                      className={`flex w-full items-center justify-between rounded-md px-3 py-2 text-sm font-medium hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
                        active
                          ? 'text-healthcare-primary dark:text-healthcare-primary-dark'
                          : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                      }`}
                    >
                      <span className="flex items-center gap-2">
                        <Icon className="h-4 w-4" />
                        {domain.label}
                      </span>
                      <ChevronDown className={`h-4 w-4 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                    </button>
                    {isOpen && (
                      <div className="ml-4 border-l border-healthcare-border pl-2 dark:border-healthcare-border-dark">
                        {domain.dashboardHref && (
                          <Link
                            href={domain.dashboardHref}
                            onClick={close}
                            className="block rounded-md px-3 py-1.5 text-sm font-medium text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark"
                          >
                            {domain.dashboardLabel}
                          </Link>
                        )}
                        {domain.groups.map((group) => {
                          const items = group.items.filter((item) => !item.adminOnly || isAdmin);
                          if (items.length === 0) return null;
                          return (
                            <div key={group.title || domain.key} className="mt-1">
                              {group.title && (
                                <div className="px-3 pt-1 text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                  {group.title}
                                </div>
                              )}
                              {items.map((item) => (
                                <Link
                                  key={item.href}
                                  href={item.href}
                                  onClick={close}
                                  className="block rounded-md px-3 py-1.5 text-sm text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark"
                                >
                                  {item.label}
                                </Link>
                              ))}
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </div>
                );
              })}
            </nav>
          </div>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/components/MobileNavMenu.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Navigation/MobileNavMenu.tsx tests/js/components/MobileNavMenu.test.tsx
git commit -m "feat(nav): MobileNavMenu hamburger + accordion"
```

---

## Task 7: TopNavbar (the single bar)

**Files:**
- Create: `resources/js/Components/Navigation/TopNavbar.tsx`
- Test: `tests/js/components/TopNavbar.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/js/components/TopNavbar.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { usePage } from '@inertiajs/react';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';

// CommandPalette pulls in cmdk; stub it to keep this test focused on the bar.
vi.mock('@/components/ui/CommandPalette', () => ({
  CommandPalette: () => null,
}));

function mockPage(overrides: Record<string, unknown>) {
  vi.mocked(usePage).mockReturnValue({
    url: '/dashboard',
    props: { auth: { user: { id: 1, name: 'Test', email: 't@x.io' }, ...overrides } },
    component: 'X',
    version: '1',
  } as never);
}

describe('TopNavbar', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders the Dashboard link and the five non-admin domain triggers', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('link', { name: /^Dashboard$/ })).toBeInTheDocument();
    for (const label of ['RTDC', 'Perioperative', 'Emergency', 'Improvement', 'Analytics']) {
      expect(screen.getByRole('button', { name: new RegExp(label, 'i') })).toBeInTheDocument();
    }
  });

  it('hides the Admin trigger for non-admins', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.queryByRole('button', { name: /^Admin$/ })).not.toBeInTheDocument();
  });

  it('shows the Admin trigger for admins', () => {
    mockPage({ is_admin: true });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('button', { name: /Admin/i })).toBeInTheDocument();
  });

  it('exposes a search button that opens the command palette', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('button', { name: /search/i })).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/components/TopNavbar.test.tsx`
Expected: FAIL — cannot resolve `@/Components/Navigation/TopNavbar`.

- [ ] **Step 3: Create the component**

Create `resources/js/Components/Navigation/TopNavbar.tsx`:

```tsx
import type { Dispatch, SetStateAction } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';
import { CommandPalette } from '@/components/ui/CommandPalette';
import { useUIStore } from '@/stores/uiStore';
import {
  NAVIGATION,
  TOP_LEVEL_DASHBOARD,
  isDomainActive,
  visibleDomains,
} from '@/config/navigationConfig';
import type { PageProps } from '@/types';
import { NavMegaMenu } from './NavMegaMenu';
import { UserMenu } from './UserMenu';
import { MobileNavMenu } from './MobileNavMenu';

interface TopNavbarProps {
  isDarkMode: boolean;
  setIsDarkMode: Dispatch<SetStateAction<boolean>>;
}

export function TopNavbar({ isDarkMode, setIsDarkMode }: TopNavbarProps) {
  const page = usePage<PageProps>();
  const url = page.url ?? '';
  const isAdmin = Boolean(page.props.auth?.is_admin);
  const domains = visibleDomains(isAdmin);
  const setCommandPaletteOpen = useUIStore((s) => s.setCommandPaletteOpen);
  const dashboardActive = url === TOP_LEVEL_DASHBOARD.href || url === '/';

  return (
    <>
      <nav className="sticky top-0 z-[65] border-b border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="mx-auto flex h-16 max-w-full items-center justify-between px-4">
          {/* Left: mobile hamburger + logo */}
          <div className="flex items-center gap-2">
            <MobileNavMenu isAdmin={isAdmin} url={url} />
            <Link href="/dashboard" className="flex items-center gap-2">
              <img src="/images/IconOnly_Transparent.png" alt="Zephyrus" className="h-9 w-auto" />
              <span className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Zephyrus
              </span>
            </Link>
          </div>

          {/* Center: desktop domain navigation */}
          <div className="hidden items-center gap-1 lg:flex">
            <Link
              href={TOP_LEVEL_DASHBOARD.href}
              aria-current={dashboardActive ? 'page' : undefined}
              className={`rounded-md px-3 py-2 text-sm font-medium transition-all duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
                dashboardActive
                  ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-primary-dark'
                  : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
              }`}
            >
              {TOP_LEVEL_DASHBOARD.label}
            </Link>
            {domains.map((domain) => (
              <NavMegaMenu
                key={domain.key}
                domain={domain}
                isAdmin={isAdmin}
                active={isDomainActive(domain, url)}
              />
            ))}
          </div>

          {/* Right: search, dark mode, user */}
          <div className="flex items-center gap-2">
            <button
              type="button"
              aria-label="Search (Cmd+K)"
              onClick={() => setCommandPaletteOpen(true)}
              className="rounded-md border border-transparent p-2 text-healthcare-text-secondary transition-all duration-300 hover:border-healthcare-border hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
            >
              <Search className="h-5 w-5" />
            </button>
            <DarkModeToggle isDarkMode={isDarkMode} onToggle={() => setIsDarkMode(!isDarkMode)} />
            <UserMenu isAdmin={isAdmin} />
          </div>
        </div>
      </nav>
      <CommandPalette />
    </>
  );
}
```

Note: `NAVIGATION` is imported only for type-coherence with the config module; it is referenced via `visibleDomains`. If `tsc` flags it as unused, remove the bare `NAVIGATION` import (keep `visibleDomains`, `isDomainActive`, `TOP_LEVEL_DASHBOARD`).

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/components/TopNavbar.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Navigation/TopNavbar.tsx tests/js/components/TopNavbar.test.tsx
git commit -m "feat(nav): TopNavbar single consolidated bar"
```

---

## Task 8: Rebuild CommandPalette from config

**Files:**
- Modify: `resources/js/components/ui/CommandPalette.tsx`
- Modify: `tests/js/components/CommandPalette.test.tsx` (add one assertion)

- [ ] **Step 1: Add a config-driven assertion to the existing test**

In `tests/js/components/CommandPalette.test.tsx`, inside the `describe('navigation items', ...)` block, add this test after the existing `includes RTDC in navigation items` test:

```tsx
    it('includes config-driven sub-pages like Bed Tracking', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      render(React.createElement(CommandPalette));

      expect(screen.getByText('Bed Tracking')).toBeInTheDocument();
    });
```

- [ ] **Step 2: Run test to verify the new assertion fails**

Run: `npx vitest run tests/js/components/CommandPalette.test.tsx`
Expected: FAIL on `includes config-driven sub-pages like Bed Tracking` (current palette has no "Bed Tracking" entry). The other tests still pass.

- [ ] **Step 3: Rewrite CommandPalette to consume the config**

Replace the entire contents of `resources/js/components/ui/CommandPalette.tsx` with:

```tsx
import React, { useEffect } from 'react';
import { Command } from 'cmdk';
import { router, usePage } from '@inertiajs/react';
import { useUIStore } from '@/stores/uiStore';
import { flattenNavigation } from '@/config/navigationConfig';
import type { PageProps } from '@/types';

export function CommandPalette() {
  const open = useUIStore((s) => s.commandPaletteOpen);
  const setOpen = useUIStore((s) => s.setCommandPaletteOpen);
  const page = usePage<PageProps>();
  const isAdmin = Boolean(page.props.auth?.is_admin);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        setOpen(!open);
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [open, setOpen]);

  if (!open) return null;

  const entries = flattenNavigation(isAdmin);
  const groups = entries.reduce<Record<string, typeof entries>>((acc, item) => {
    (acc[item.group] ??= []).push(item);
    return acc;
  }, {});

  return (
    <div className="fixed inset-0 z-50">
      <div className="fixed inset-0 bg-black/50 backdrop-blur-sm" onClick={() => setOpen(false)} />
      <div className="fixed top-[20%] left-1/2 w-full max-w-lg -translate-x-1/2">
        <Command className="rounded-xl border border-gray-700 bg-gray-900 shadow-2xl" label="Command Palette">
          <Command.Input
            className="w-full border-b border-gray-700 bg-transparent px-4 py-3 text-sm text-gray-100 placeholder-gray-500 outline-none"
            placeholder="Search pages and actions..."
            autoFocus
          />
          <Command.List className="max-h-80 overflow-y-auto p-2">
            <Command.Empty className="px-4 py-6 text-center text-sm text-gray-500">
              No results found.
            </Command.Empty>
            {Object.entries(groups).map(([group, items]) => (
              <Command.Group
                key={group}
                heading={group}
                className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-gray-500"
              >
                {items.map((item) => (
                  <Command.Item
                    key={item.href}
                    value={`${item.group} ${item.label}`}
                    onSelect={() => {
                      setOpen(false);
                      router.visit(item.href);
                    }}
                    className="flex cursor-pointer items-center rounded-lg px-3 py-2 text-sm text-gray-300 aria-selected:bg-gray-800 aria-selected:text-white"
                  >
                    {item.label}
                  </Command.Item>
                ))}
              </Command.Group>
            ))}
          </Command.List>
          <div className="border-t border-gray-700 px-4 py-2 text-xs text-gray-500">
            <span className="mr-3">
              <kbd className="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] font-medium text-gray-400">↑↓</kbd> Navigate
            </span>
            <span className="mr-3">
              <kbd className="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] font-medium text-gray-400">↵</kbd> Select
            </span>
            <span>
              <kbd className="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] font-medium text-gray-400">Esc</kbd> Close
            </span>
          </div>
        </Command>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run the full CommandPalette test**

Run: `npx vitest run tests/js/components/CommandPalette.test.tsx`
Expected: PASS (the `Navigation` group still exists, `Dashboard`/`RTDC` still present, and `Bed Tracking` now present).

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/ui/CommandPalette.tsx tests/js/components/CommandPalette.test.tsx
git commit -m "feat(nav): drive CommandPalette from navigationConfig (Cmd+K now finds sub-pages)"
```

---

## Task 9: Wire TopNavbar into DashboardLayout

**Files:**
- Modify: `resources/js/Components/Dashboard/DashboardLayout.jsx`

- [ ] **Step 1: Swap the import and usage**

Replace the entire contents of `resources/js/Components/Dashboard/DashboardLayout.jsx` with:

```jsx
import React from 'react';
import { useDarkMode } from '@/hooks/useDarkMode';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';

const DashboardLayout = ({ children }) => {
    const [isDarkMode, setIsDarkMode] = useDarkMode();

    return (
        <div className="min-h-screen bg-healthcare-background dark:bg-healthcare-background-dark transition-colors duration-300">
            <TopNavbar isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />
            <main className="py-6 px-6 max-w-full overflow-x-hidden transition-colors duration-300">
                {children}
            </main>
        </div>
    );
};

export default DashboardLayout;
```

- [ ] **Step 2: Verify build + a page test that uses this layout**

Run: `npx vitest run tests/js/rtdc/global-huddle.test.tsx`
Expected: PASS (this test stubs `RTDCPageLayout`, so it is unaffected, but confirms nothing regressed).

Run: `npx tsc --noEmit`
Expected: no new errors in `DashboardLayout.jsx`.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Dashboard/DashboardLayout.jsx
git commit -m "feat(nav): render TopNavbar in DashboardLayout"
```

---

## Task 10: Rewire AuthenticatedLayout (remove sidebar/drawer)

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.tsx`

- [ ] **Step 1: Replace the layout body**

Replace the entire contents of `resources/js/Layouts/AuthenticatedLayout.tsx` with:

```tsx
import React, { createContext, useContext, useState, useEffect } from 'react';
import type { ReactNode, Dispatch, SetStateAction } from 'react';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';
import ChangePasswordModal from '@/Components/ChangePasswordModal';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

interface DarkModeContextType {
    isDarkMode: boolean;
    setIsDarkMode: Dispatch<SetStateAction<boolean>>;
}

interface AuthenticatedLayoutProps {
    header?: ReactNode;
    children: ReactNode;
}

// Create a context for dark mode
export const DarkModeContext = createContext<DarkModeContextType>({
    isDarkMode: false,
    setIsDarkMode: () => {}
});

// Custom hook to use dark mode
export const useDarkMode = (): DarkModeContextType => useContext(DarkModeContext);

/* Google Fonts for Acumenus Clinical Design System */
const GOOGLE_FONTS_URL =
    'https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@400;600;700&family=Source+Serif+4:wght@400;600;700&family=Source+Sans+3:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap';

export default function AuthenticatedLayout({ header, children }: AuthenticatedLayoutProps) {
    const { auth } = usePage<PageProps>().props;
    const mustChangePassword = auth?.user?.must_change_password;
    const [isDarkMode, setIsDarkMode] = useState<boolean>(() => {
        const savedTheme = localStorage.getItem('darkMode');
        return savedTheme === 'true' || (savedTheme === null && window.matchMedia('(prefers-color-scheme: dark)').matches) || savedTheme === null;
    });

    // Set localStorage when dark mode changes
    useEffect(() => {
        localStorage.setItem('darkMode', String(isDarkMode));
        if (isDarkMode) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }, [isDarkMode]);

    // Inject Google Fonts link if not already present
    useEffect(() => {
        const existingLink = document.querySelector(`link[href="${GOOGLE_FONTS_URL}"]`);
        if (existingLink) return;

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = GOOGLE_FONTS_URL;
        document.head.appendChild(link);
    }, []);

    return (
        <DarkModeContext.Provider value={{ isDarkMode, setIsDarkMode }}>
            {/* Skip to content link for accessibility */}
            <a href="#main-content" className="skip-to-content">
                Skip to content
            </a>

            <div
                style={{
                    minHeight: '100vh',
                    background: 'var(--surface-base)',
                    color: 'var(--text-primary)',
                    fontFamily: 'var(--font-body)',
                    display: 'flex',
                    flexDirection: 'column',
                }}
            >
                {/* Force password change modal — preserved per auth-system rules */}
                {mustChangePassword && <ChangePasswordModal />}

                {/* Single consolidated top navbar (mounts CommandPalette internally) */}
                <TopNavbar isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />

                {/* Header */}
                {header && (
                    <header
                        style={{
                            background: 'var(--surface-raised)',
                            borderBottom: '1px solid var(--border-subtle)',
                            boxShadow: 'var(--shadow-xs)',
                        }}
                    >
                        <div
                            style={{
                                maxWidth: 'var(--content-max-width)',
                                margin: '0 auto',
                                padding: `var(--space-4) var(--content-padding)`,
                            }}
                        >
                            {header}
                        </div>
                    </header>
                )}

                {/* Main Content */}
                <main
                    id="main-content"
                    style={{
                        flex: 1,
                        maxWidth: 'var(--content-max-width)',
                        width: '100%',
                        margin: '0 auto',
                    }}
                >
                    {children}
                </main>
            </div>
        </DarkModeContext.Provider>
    );
}
```

Note: `useDarkMode` (the context hook) is still exported because `AnalyticsLayout.jsx` imports it. `ChangePasswordModal`, `DarkModeContext`, fonts, and skip-link are preserved per `.claude/rules/auth-system.md`. The standalone `<CommandPalette />`, `<Sidebar />`, `<MobileDrawer />`, and `useUIStore().sidebarOpen` are removed (palette now lives in `TopNavbar`).

- [ ] **Step 2: Verify build**

Run: `npx tsc --noEmit`
Expected: no new errors in `AuthenticatedLayout.tsx`. (If an unused-import error appears for a removed symbol, delete that import line.)

Run: `npx vitest run`
Expected: all tests pass.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.tsx
git commit -m "feat(nav): AuthenticatedLayout uses TopNavbar, drops sidebar/drawer"
```

---

## Task 11: Emergency stub pages

Make every Emergency menu link resolve. The 9 controller methods already render
`ED/Analytics/{WaitTime,Flow,Resources}`, `ED/Operations/{Triage,Treatment,Resources}`,
`ED/Predictions/{Arrival,Acuity,Resources}` — create those pages.

**Files:**
- Create: `resources/js/Components/ED/EDPlaceholder.jsx`
- Create: the 9 page files listed below.

- [ ] **Step 1: Create the shared placeholder body**

Create `resources/js/Components/ED/EDPlaceholder.jsx`:

```jsx
import React from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Construction } from 'lucide-react';

const EDPlaceholder = ({ title, subtitle }) => {
    return (
        <DashboardLayout>
            <Head title={`${title} - Emergency`} />
            <PageContentLayout title={title} subtitle={subtitle}>
                <div className="flex flex-col items-center justify-center rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark py-16 text-center shadow-sm transition-colors duration-300">
                    <div className="mb-4 rounded-full bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/10 p-4">
                        <Construction className="h-8 w-8 text-healthcare-primary dark:text-healthcare-primary-dark" />
                    </div>
                    <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {title}
                    </h3>
                    <p className="mt-2 max-w-md text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        This Emergency Department view is coming soon.
                    </p>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default EDPlaceholder;
```

- [ ] **Step 2: Create the 9 thin pages**

Create `resources/js/Pages/ED/Analytics/WaitTime.jsx`:

```jsx
import React from 'react';
import EDPlaceholder from '@/Components/ED/EDPlaceholder';

export default function WaitTime() {
    return <EDPlaceholder title="Wait Time" subtitle="Monitor and analyze ED patient wait times" />;
}
```

Create `resources/js/Pages/ED/Analytics/Flow.jsx`:

```jsx
import React from 'react';
import EDPlaceholder from '@/Components/ED/EDPlaceholder';

export default function Flow() {
    return <EDPlaceholder title="Patient Flow" subtitle="Assess patient movement through the ED" />;
}
```

Create `resources/js/Pages/ED/Analytics/Resources.jsx`:

```jsx
import React from 'react';
import EDPlaceholder from '@/Components/ED/EDPlaceholder';

export default function Resources() {
    return <EDPlaceholder title="Resource Analytics" subtitle="Analyze ED resource utilization" />;
}
```

Create `resources/js/Pages/ED/Operations/Triage.jsx`:

```jsx
import React from 'react';
import EDPlaceholder from '@/Components/ED/EDPlaceholder';

export default function Triage() {
    return <EDPlaceholder title="Triage" subtitle="Manage triage operations and patient prioritization" />;
}
```

Create `resources/js/Pages/ED/Operations/Treatment.jsx`:

```jsx
import React from 'react';
import EDPlaceholder from '@/Components/ED/EDPlaceholder';

export default function Treatment() {
    return <EDPlaceholder title="Treatment" subtitle="Oversee treatment procedures and protocols" />;
}
```

Create `resources/js/Pages/ED/Operations/Resources.jsx`:

```jsx
import React from 'react';
import EDPlaceholder from '@/Components/ED/EDPlaceholder';

export default function Resources() {
    return <EDPlaceholder title="Resource Management" subtitle="Manage ED resources and staffing" />;
}
```

Create `resources/js/Pages/ED/Predictions/Arrival.jsx`:

```jsx
import React from 'react';
import EDPlaceholder from '@/Components/ED/EDPlaceholder';

export default function Arrival() {
    return <EDPlaceholder title="Arrival Prediction" subtitle="Forecast patient arrivals to the ED" />;
}
```

Create `resources/js/Pages/ED/Predictions/Acuity.jsx`:

```jsx
import React from 'react';
import EDPlaceholder from '@/Components/ED/EDPlaceholder';

export default function Acuity() {
    return <EDPlaceholder title="Acuity Prediction" subtitle="Forecast patient acuity mix" />;
}
```

Create `resources/js/Pages/ED/Predictions/Resources.jsx`:

```jsx
import React from 'react';
import EDPlaceholder from '@/Components/ED/EDPlaceholder';

export default function Resources() {
    return <EDPlaceholder title="Resource Optimization" subtitle="Optimize resource allocation from predictions" />;
}
```

- [ ] **Step 3: Verify the pages resolve under Vite**

Run: `npx vite build`
Expected: SUCCESS — no `UNRESOLVED_IMPORT`. (Inertia resolves these via the glob in `resources/js/app.tsx`; a successful build confirms they compile.)

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/ED/EDPlaceholder.jsx resources/js/Pages/ED
git commit -m "feat(ed): add 9 Emergency placeholder pages so nav links resolve"
```

---

## Task 12: Delete dead navigation files

**Files:**
- Delete: `resources/js/components/layout/Sidebar.tsx`, `resources/js/components/layout/MobileDrawer.tsx`, `resources/js/Components/Navigation/TopNavigation.jsx`
- Conditionally delete: `resources/js/Components/NavLink.jsx`, `resources/js/Components/ResponsiveNavLink.jsx`
- Modify (if needed): `resources/js/stores/uiStore.ts`

- [ ] **Step 1: Confirm there are no remaining importers**

Run:
```bash
cd /home/smudoshi/Github/Zephyrus
grep -rn "components/layout/Sidebar\|components/layout/MobileDrawer\|Navigation/TopNavigation\|@/Components/NavLink\|@/Components/ResponsiveNavLink\|ResponsiveNavLink\|from './NavLink'\|from '@/Components/NavLink'" resources/js --include=*.tsx --include=*.jsx --include=*.ts
```
Expected: no matches (or only the files themselves / the deleted layout edits). If a match exists in a real consumer, STOP and resolve that import first.

- [ ] **Step 2: Delete the confirmed-dead files**

```bash
git rm resources/js/components/layout/Sidebar.tsx \
       resources/js/components/layout/MobileDrawer.tsx \
       resources/js/Components/Navigation/TopNavigation.jsx
```

Only if Step 1 showed NavLink/ResponsiveNavLink are unreferenced:
```bash
git rm resources/js/Components/NavLink.jsx resources/js/Components/ResponsiveNavLink.jsx
```

- [ ] **Step 3: Remove now-unused `sidebarOpen` from the UI store**

Check remaining usage:
```bash
grep -rn "sidebarOpen\|toggleSidebar\|mobileDrawerOpen\|setMobileDrawerOpen" resources/js --include=*.tsx --include=*.jsx --include=*.ts
```
For each field with no remaining non-test usage, remove it from `resources/js/stores/uiStore.ts`. If `tests/js/stores/uiStore.test.ts` or `tests/js/components/CommandPalette.test.tsx` reference removed fields, update those `setState`/assertions accordingly. If everything still references them, leave the store unchanged. The `commandPaletteOpen`/`setCommandPaletteOpen` fields MUST remain.

- [ ] **Step 4: Verify nothing broke**

Run: `npx tsc --noEmit`
Expected: no errors referencing the deleted files.

Run: `npx vitest run`
Expected: all tests pass.

Run: `npx vite build`
Expected: SUCCESS, no `UNRESOLVED_IMPORT`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore(nav): remove sidebar, mobile drawer, legacy TopNavigation"
```

---

## Task 13: Full verification + deploy

- [ ] **Step 1: Full type-check, test, and strict build**

```bash
cd /home/smudoshi/Github/Zephyrus
npx tsc --noEmit && npx vitest run && npx vite build
```
Expected: type-check clean of new errors, all vitest suites green, vite build SUCCESS (vite build is stricter than tsc — it catches `UNRESOLVED_IMPORT`).

- [ ] **Step 2: Manual click-through (dev server)**

Start the dev server (`npm run dev` + the Laravel app per project setup) and, logged in as a normal user then as `admin@acumenus.net`, click EVERY navbar link:
- Top-level **Dashboard**.
- Each domain dropdown (**RTDC, Perioperative, Emergency, Improvement, Analytics**) → open it, click every item; confirm no Inertia "page not found" overlay.
- **Admin** dropdown appears ONLY for the admin user; **User Management** appears in the user menu ONLY for the admin user.
- Resize below 1024px → hamburger appears, opens the accordion, every link works, menu closes on navigation.
- Press **Cmd/Ctrl+K** on a `DashboardLayout` page (e.g. `/improvement/library`) AND on an `AuthenticatedLayout` page → palette opens on both; searching "Bed Tracking" navigates correctly.
- Toggle dark mode → persists across navigation.
- Confirm the forced-password-change flow is unchanged (the `ChangePasswordModal` still blocks when `must_change_password`).

Record any dead link or console error and fix before proceeding.

- [ ] **Step 3: Deploy the frontend**

Per project convention (`./deploy.sh --frontend` builds in dev and rsyncs to `/var/www/Zephyrus`):
```bash
cd /home/smudoshi/Github/Zephyrus
./deploy.sh --frontend
```
Expected: build + rsync complete without error.

- [ ] **Step 4: Final commit / branch is ready for PR**

```bash
git status   # should be clean
git log --oneline origin/main..HEAD   # review the full task series
```
The branch `feature/navbar-consolidation` is now ready to open a PR (use the project's PR workflow).

---

## Self-Review Notes

- **Spec coverage:** single bar (T7,9,10) ✓; domain-led always-visible with mega-menus (T2,4,5) ✓; Improvement grouped Diagnose/Improve (T2) ✓; Analytics distinct (T2) ✓; RTDC Risk + dead superuser routes excluded (T2 + test) ✓; 9 ED stubs (T11) ✓; one config feeds navbar+mobile+palette (T2,6,7,8) ✓; auth components preserved (T10) ✓; sidebar/drawer/legacy removed (T12) ✓; tsc+vite+tests green (T13) ✓; admin gating navbar+usermenu+palette (T3,7,8 + tests) ✓.
- **No placeholders:** every code step shows complete file content or an exact insertion.
- **Type consistency:** `NavDomain`/`NavGroup`/`NavLeaf`/`FlatNavEntry`, `visibleDomains`/`isDomainActive`/`flattenNavigation`/`TOP_LEVEL_DASHBOARD`, `getUserMenuItems`/`UserMenuItem`, and the `{ isDarkMode, setIsDarkMode: Dispatch<SetStateAction<boolean>> }` props are used identically across all tasks.
