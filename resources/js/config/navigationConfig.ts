// resources/js/config/navigationConfig.ts
//
// The navigation SSOT (navbar + command palette). Zephyrus 2.0 P4a shapes it
// around the Altitude model: COCKPIT (the one home, /dashboard, no submenu),
// WORKSPACES (the "now" surfaces — each domain's header link points at its
// primary live workspace, never a retired /dashboard/* overview), STUDY (the
// retrospective altitude — Analytics + Improvement, merged fully in P5), and
// ADMIN. Legacy /dashboard/* overview URLs live on as server-side redirects
// into the cockpit drill layer (routes/web.php) but are never linked here.
import type { LucideIcon } from 'lucide-react';
import {
  Activity,
  AlertCircle,
  Ambulance,
  ArrowRightCircle,
  BarChart3,
  BedDouble,
  BookOpen,
  Bot,
  Boxes,
  CalendarClock,
  ClipboardList,
  ClipboardCheck,
  Clock,
  DoorOpen,
  FileText,
  Gauge,
  GitBranch,
  HeartPulse,
  MapPinned,
  LineChart,
  ListChecks,
  PieChart,
  RefreshCcw,
  Repeat,
  Route,
  Search,
  Shield,
  Siren,
  Stethoscope,
  Timer,
  TrendingUp,
  Truck,
  UserCog,
  Users,
  Workflow,
  Building2,
  Settings,
  Send,
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
  /** Prefixes carved OUT of matchPrefixes — pages re-homed to another
   *  altitude (P5: workspace trend pages glow Study, not their old domain). */
  readonly excludePrefixes?: readonly string[];
  readonly groups: readonly NavGroup[];
  readonly adminOnly?: boolean;
}

/** One of the four altitude sections the top bar renders. */
export interface NavSection {
  readonly key: string;
  readonly title: string;
  /** COCKPIT only: a plain home link with no dropdown domains. */
  readonly homeHref?: string;
  readonly domains: readonly NavDomain[];
}

export const TOP_LEVEL_DASHBOARD = { label: 'Cockpit', href: '/dashboard' } as const;

const RTDC: NavDomain = {
  key: 'rtdc',
  label: 'RTDC',
  icon: BedDouble,
  dashboardHref: '/rtdc/bed-tracking',
  dashboardLabel: 'Bed Tracking Board',
  matchPrefixes: ['/rtdc'],
  // P5: /rtdc/analytics/* re-homed to the Study altitude (Analytics domain).
  excludePrefixes: ['/rtdc/analytics'],
  groups: [
    {
      title: 'Operations',
      items: [
        { label: 'Bed Tracking', href: '/rtdc/bed-tracking', icon: Activity },
        { label: 'Patient Flow 4D', href: '/rtdc/patient-flow-navigator', icon: Workflow },
        { label: 'Bed Placement', href: '/rtdc/bed-placement', icon: ClipboardList },
        { label: 'Ancillary Services', href: '/rtdc/ancillary-services', icon: Boxes },
        { label: 'Global Huddle', href: '/rtdc/global-huddle', icon: Users },
        { label: 'Unit Huddle', href: '/rtdc/unit-huddle', icon: Users },
        { label: 'Service Huddle', href: '/rtdc/service-huddle', icon: Users },
      ],
    },
    {
      title: 'Predictions',
      items: [
        { label: 'Demand', href: '/rtdc/predictions/demand', icon: PieChart },
        { label: 'Resources', href: '/rtdc/predictions/resources', icon: Boxes },
        { label: 'Discharge', href: '/rtdc/predictions/discharge', icon: ArrowRightCircle },
        { label: 'Risk Assessment', href: '/rtdc/predictions/risk', icon: AlertCircle },
      ],
    },
  ],
};

const EMERGENCY: NavDomain = {
  key: 'emergency',
  label: 'Emergency',
  icon: Siren,
  dashboardHref: '/ed/operations/triage',
  dashboardLabel: 'ED Triage Board',
  matchPrefixes: ['/ed'],
  // P5: the two retrospective ED pages re-homed to Study; /ed/analytics/flow
  // stays — it is the live 4D navigator (a "now" surface despite its URL).
  excludePrefixes: ['/ed/analytics/wait-time', '/ed/analytics/resources'],
  groups: [
    {
      title: 'Operations',
      items: [
        { label: 'Triage', href: '/ed/operations/triage', icon: ListChecks },
        { label: 'Treatment', href: '/ed/operations/treatment', icon: HeartPulse },
        { label: 'Resources', href: '/ed/operations/resources', icon: Boxes },
        { label: 'Patient Flow', href: '/ed/analytics/flow', icon: Workflow },
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
};

const PERIOPERATIVE: NavDomain = {
  key: 'perioperative',
  label: 'Perioperative',
  icon: Stethoscope,
  dashboardHref: '/operations/room-status',
  dashboardLabel: 'OR Room Status',
  matchPrefixes: ['/operations', '/predictions'],
  groups: [
    {
      title: 'Operations',
      items: [
        { label: 'Room Status', href: '/operations/room-status', icon: DoorOpen },
        { label: 'Block Schedule', href: '/operations/block-schedule', icon: CalendarClock },
        { label: 'Case Management', href: '/operations/cases', icon: ClipboardList },
      ],
    },
    // P5: the 5 surgical deep-dives live ONLY under Study (Analytics domain) —
    // the workspace reaches them via the in-page "deep dive" affordance, never
    // a duplicated nav leaf.
    {
      title: 'Predictions',
      items: [
        { label: 'Utilization Forecast', href: '/predictions/forecast', icon: LineChart },
        { label: 'Demand Analysis', href: '/predictions/demand', icon: PieChart },
        { label: 'Resource Planning', href: '/predictions/resources', icon: Boxes },
      ],
    },
  ],
};

const TRANSPORT: NavDomain = {
  key: 'transport',
  label: 'Transport',
  icon: Truck,
  dashboardHref: '/transport/dispatch',
  dashboardLabel: 'Dispatch Board',
  matchPrefixes: ['/transport'],
  // P5: /transport/analytics re-homed to Study (TransportLayout keeps its
  // Analytics tab as the in-workspace affordance).
  excludePrefixes: ['/transport/analytics'],
  groups: [
    {
      title: 'Operations',
      items: [
        { label: 'Requests', href: '/transport/requests', icon: ClipboardList },
        { label: 'Dispatch', href: '/transport/dispatch', icon: Route },
        { label: 'Inpatient', href: '/transport/inpatient', icon: BedDouble },
        { label: 'Transfers', href: '/transport/transfers', icon: Building2 },
        { label: 'Discharge', href: '/transport/discharge', icon: Send },
        { label: 'EMS', href: '/transport/ems', icon: Ambulance },
        { label: 'Care Transitions', href: '/transport/care-transitions', icon: ClipboardCheck },
      ],
    },
    {
      title: 'Control',
      items: [
        { label: 'Resources', href: '/transport/resources', icon: MapPinned },
        { label: 'Integrations', href: '/transport/settings/integrations', icon: Settings },
      ],
    },
  ],
};

const STAFFING: NavDomain = {
  key: 'staffing',
  label: 'Staffing',
  icon: UserCog,
  dashboardHref: '/staffing',
  dashboardLabel: 'Staffing Office',
  matchPrefixes: ['/staffing'],
  // The workspace IS the single page — the panel is just its header link.
  groups: [],
};

// The house-wide flow workspace (cockpit domain 'flow'): the pages that answer
// "is the house moving?" — cross-listed from RTDC/Transport, deduped in the
// palette by flattenNavigation.
const PATIENT_FLOW: NavDomain = {
  key: 'flow',
  label: 'Patient Flow',
  icon: Workflow,
  dashboardHref: '/rtdc/patient-flow-navigator',
  dashboardLabel: 'Patient Flow 4D',
  matchPrefixes: ['/rtdc/patient-flow-navigator', '/transport/care-transitions'],
  groups: [
    {
      title: 'Operations',
      items: [
        { label: 'Patient Flow 4D', href: '/rtdc/patient-flow-navigator', icon: Workflow },
        { label: 'Bed Placement', href: '/rtdc/bed-placement', icon: ClipboardList },
        { label: 'Care Transitions', href: '/transport/care-transitions', icon: ClipboardCheck },
      ],
    },
  ],
};

const ANALYTICS: NavDomain = {
  key: 'analytics',
  label: 'Analytics',
  icon: BarChart3,
  dashboardHref: '/analytics',
  dashboardLabel: 'Operations Intelligence',
  matchPrefixes: [
    '/analytics',
    '/ops',
    // P5: per-domain trend pages re-homed from their workspaces.
    '/rtdc/analytics',
    '/ed/analytics/wait-time',
    '/ed/analytics/resources',
    '/transport/analytics',
  ],
  groups: [
    {
      title: 'Control',
      items: [
        { label: 'Intelligence Hub', href: '/analytics', icon: BarChart3 },
        { label: 'Live Signals', href: '/analytics/live', icon: Activity },
        { label: 'Data Quality', href: '/analytics/data-quality', icon: Shield },
      ],
    },
    {
      title: 'Operations Console',
      items: [
        { label: 'Agent Inbox', href: '/ops/agent-inbox', icon: Bot },
        { label: 'Executive Brief', href: '/ops/executive-brief', icon: FileText },
      ],
    },
    {
      title: 'Patterns',
      items: [
        { label: 'Retrospective Review', href: '/analytics/retrospective', icon: TrendingUp },
        { label: 'Process Intelligence', href: '/analytics/process-intelligence', icon: Workflow },
        // Part X (X1) — object-centric process maps discovered from the OCEL log.
        { label: 'Patient-Flow Arena', href: '/analytics/arena', icon: GitBranch },
      ],
    },
    {
      title: 'Forecast',
      items: [
        { label: 'Predictive Planning', href: '/analytics/predictive', icon: LineChart },
        { label: 'Scenario Workbench', href: '/analytics/workbench', icon: Search },
      ],
    },
    {
      title: 'Improve',
      items: [
        { label: 'Opportunity Portfolio', href: '/analytics/opportunities', icon: AlertCircle },
      ],
    },
    {
      title: 'Surgical Deep Dives',
      items: [
        { label: 'Block Utilization', href: '/analytics/block-utilization', icon: BarChart3 },
        { label: 'OR Utilization', href: '/analytics/or-utilization', icon: Gauge },
        { label: 'Primetime Utilization', href: '/analytics/primetime-utilization', icon: Clock },
        { label: 'Room Running', href: '/analytics/room-running', icon: Activity },
        { label: 'Turnover Times', href: '/analytics/turnover-times', icon: Timer },
      ],
    },
    // P5: per-domain retrospective pages, re-homed from their workspaces —
    // the temporal split ("now" = workspace, "over time" = Study).
    {
      title: 'Domain Trends',
      items: [
        { label: 'RTDC Utilization', href: '/rtdc/analytics/utilization', icon: Gauge },
        { label: 'RTDC Performance', href: '/rtdc/analytics/performance', icon: LineChart },
        { label: 'RTDC Resources', href: '/rtdc/analytics/resources', icon: Boxes },
        { label: 'RTDC Trends', href: '/rtdc/analytics/trends', icon: TrendingUp },
        { label: 'ED Wait Time', href: '/ed/analytics/wait-time', icon: Clock },
        { label: 'ED Resources', href: '/ed/analytics/resources', icon: Boxes },
        { label: 'Transport Analytics', href: '/transport/analytics', icon: BarChart3 },
      ],
    },
  ],
};

const IMPROVEMENT: NavDomain = {
  key: 'improvement',
  label: 'Improvement',
  icon: TrendingUp,
  dashboardHref: '/improvement/active',
  dashboardLabel: 'Active Cycles',
  matchPrefixes: ['/improvement'],
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
        { label: 'PDSA Cycles', href: '/improvement/pdsa', icon: Repeat },
        { label: 'Library', href: '/improvement/library', icon: BookOpen },
      ],
    },
  ],
};

const ADMIN: NavDomain = {
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
        // P8 WS-6b — the cockpit threshold editor (band-edge tuning, audited).
        { label: 'Cockpit Thresholds', href: '/admin/cockpit/thresholds', icon: Settings, adminOnly: true },
        // NOTE: "Auth Providers" intentionally omitted — admin/auth-providers/{type}
        // is a JSON API endpoint with no Inertia page, so a nav link would break
        // navigation. Re-add once a real OIDC admin Inertia page exists.
      ],
    },
  ],
};

/** The four altitude sections (P4a). Order is the top-bar render order. */
export const NAV_SECTIONS: readonly NavSection[] = [
  { key: 'cockpit', title: 'Cockpit', homeHref: TOP_LEVEL_DASHBOARD.href, domains: [] },
  {
    key: 'workspaces',
    title: 'Workspaces',
    domains: [RTDC, EMERGENCY, PERIOPERATIVE, TRANSPORT, STAFFING, PATIENT_FLOW],
  },
  { key: 'study', title: 'Study', domains: [ANALYTICS, IMPROVEMENT] },
  { key: 'admin', title: 'Admin', domains: [ADMIN] },
];

/** Flat domain list, section order preserved (palette + active-state checks). */
export const NAVIGATION: readonly NavDomain[] = NAV_SECTIONS.flatMap((s) => s.domains);

export function isDomainActive(domain: NavDomain, url: string): boolean {
  const path = (url || '').split('?')[0].split('#')[0];
  const hits = (p: string): boolean => path === p || path.startsWith(`${p}/`);
  if (domain.excludePrefixes?.some(hits)) return false;
  return domain.matchPrefixes.some(hits);
}

export function visibleDomains(isAdmin: boolean): readonly NavDomain[] {
  return NAVIGATION.filter((d) => !d.adminOnly || isAdmin);
}

/** Sections with admin-gated domains filtered; empty domain sections drop
 *  (the COCKPIT section survives on its homeHref). */
export function visibleSections(isAdmin: boolean): readonly NavSection[] {
  return NAV_SECTIONS.map((section) => ({
    ...section,
    domains: section.domains.filter((d) => !d.adminOnly || isAdmin),
  })).filter((section) => section.homeHref !== undefined || section.domains.length > 0);
}

export interface FlatNavEntry {
  readonly label: string;
  readonly href: string;
  readonly group: string;
}

/** Flatten the config for the command palette, respecting admin gating.
 *  Deduplicates by href (first occurrence wins) because pages are intentionally
 *  cross-listed (the /analytics/* set, the Patient Flow workspace). Group items
 *  are pushed before each domain's header link so the descriptive page labels
 *  win the dedup — a repointed dashboardHref never eats its page's entry.
 */
export function flattenNavigation(isAdmin: boolean): readonly FlatNavEntry[] {
  const seen = new Set<string>();
  const entries: FlatNavEntry[] = [];

  function push(entry: FlatNavEntry): void {
    if (seen.has(entry.href)) return;
    seen.add(entry.href);
    entries.push(entry);
  }

  push({ label: TOP_LEVEL_DASHBOARD.label, href: TOP_LEVEL_DASHBOARD.href, group: 'Navigation' });

  for (const domain of visibleDomains(isAdmin)) {
    for (const group of domain.groups) {
      const groupLabel = group.title ? `${domain.label} ${group.title}` : domain.label;
      for (const item of group.items) {
        if (item.adminOnly && !isAdmin) continue;
        push({ label: item.label, href: item.href, group: groupLabel });
      }
    }
    if (domain.dashboardHref) {
      push({ label: domain.label, href: domain.dashboardHref, group: 'Navigation' });
    }
  }

  return entries;
}
