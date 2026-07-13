// resources/js/config/navigationConfig.ts
//
// The navigation SSOT (navbar + command palette). Zephyrus 2.0 P4a shapes it
// around the Altitude model: COCKPIT (the one home, /dashboard), WORKSPACES
// (the "now" surfaces), STUDY (retrospective analysis and improvement), and
// capability-gated INTEGRATIONS. The top bar renders only these sections. The
// complete domain/page tree is projected into desktop and mobile navigation,
// the command palette, and route-ownership tests from this file.
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
  Droplets,
  FileText,
  FlaskConical,
  Gauge,
  GitBranch,
  HeartPulse,
  MapPinned,
  LineChart,
  LayoutDashboard,
  LayoutGrid,
  ListChecks,
  PieChart,
  RefreshCcw,
  Repeat,
  Route,
  ScanLine,
  Search,
  ScrollText,
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
  PlugZap,
} from 'lucide-react';

export interface NavigationCapabilities {
  readonly view_integrations?: boolean;
  readonly view_enterprise_setup?: boolean;
  readonly manage_staffing_alignment?: boolean;
  readonly view_administration?: boolean;
  readonly view_user_audit?: boolean;
}

/** Server-shared feature flags (Inertia `features` prop). A leaf gated on a
 *  disabled feature is hidden — never rendered as a dead link that 404s. */
export interface NavigationFeatures {
  readonly virtual_rounds?: boolean;
}

export interface NavigationAccess {
  readonly isAdmin: boolean;
  readonly can?: NavigationCapabilities;
  readonly features?: NavigationFeatures;
}

export interface NavLeaf {
  readonly label: string;
  readonly href: string;
  readonly icon: LucideIcon;
  readonly adminOnly?: boolean;
  readonly requiredCapability?: keyof NavigationCapabilities;
  readonly adminOrCapability?: keyof NavigationCapabilities;
  /** Hidden unless this server-shared feature flag is on. */
  readonly requiredFeature?: keyof NavigationFeatures;
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
  readonly requiredCapability?: keyof NavigationCapabilities;
}

/** One of the section-level controls rendered in the top bar. */
export interface NavSection {
  readonly key: string;
  readonly title: string;
  readonly icon: LucideIcon;
  /** Direct sections render as a link; other sections open their domain panel. */
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
        {
          label: 'Virtual Rounds',
          href: '/rtdc/virtual-rounds',
          icon: Stethoscope,
          requiredFeature: 'virtual_rounds',
        },
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

const RADIOLOGY: NavDomain = {
  key: 'radiology',
  label: 'Radiology',
  icon: ScanLine,
  dashboardHref: '/radiology',
  dashboardLabel: 'Imaging Flow Board',
  matchPrefixes: ['/radiology'],
  groups: [
    {
      title: 'Operations',
      items: [
        { label: 'Imaging Flow Board', href: '/radiology', icon: Activity },
        { label: 'Order Worklist', href: '/radiology/worklist', icon: ClipboardList },
        { label: 'Modality Utilization', href: '/radiology/modality', icon: ScanLine },
        { label: 'Reads & Results', href: '/radiology/reads', icon: FileText },
      ],
    },
  ],
};

const LAB: NavDomain = {
  key: 'lab',
  label: 'Laboratory',
  icon: FlaskConical,
  dashboardHref: '/lab',
  dashboardLabel: 'Laboratory Flow Board',
  matchPrefixes: ['/lab'],
  groups: [
    {
      title: 'Operations',
      items: [
        { label: 'Laboratory Flow Board', href: '/lab', icon: Activity },
        { label: 'Specimen Tracker', href: '/lab/specimens', icon: FlaskConical },
        { label: 'Decision-Pending Results', href: '/lab/pending-decisions', icon: GitBranch },
        { label: 'Blood Bank Readiness', href: '/lab/blood-bank', icon: Droplets },
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
  groups: [
    {
      title: 'Administration',
      items: [
        {
          label: 'Staffing Alignment',
          href: '/staffing/administration',
          icon: UserCog,
          requiredCapability: 'manage_staffing_alignment',
        },
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
      title: 'Overview',
      items: [
        { label: 'Intelligence Hub', href: '/analytics', icon: BarChart3 },
        { label: 'Live Signals', href: '/analytics/live', icon: Activity },
        { label: 'Executive Brief', href: '/ops/executive-brief', icon: FileText },
        { label: 'Agent Inbox', href: '/ops/agent-inbox', icon: Bot },
        { label: 'Data Quality', href: '/analytics/data-quality', icon: Shield },
      ],
    },
    {
      title: 'Process Analysis',
      items: [
        { label: 'Retrospective Review', href: '/analytics/retrospective', icon: TrendingUp },
        { label: 'Process Intelligence', href: '/analytics/process-intelligence', icon: Workflow },
        // Part X (X1) — object-centric process maps discovered from the OCEL log.
        { label: 'Patient-Flow Arena', href: '/analytics/arena', icon: GitBranch },
        { label: 'Opportunity Portfolio', href: '/analytics/opportunities', icon: AlertCircle },
      ],
    },
    {
      title: 'Planning',
      items: [
        { label: 'Predictive Planning', href: '/analytics/predictive', icon: LineChart },
        { label: 'Scenario Workbench', href: '/analytics/workbench', icon: Search },
      ],
    },
    {
      title: 'Perioperative Performance',
      items: [
        { label: 'Block Utilization', href: '/analytics/block-utilization', icon: BarChart3 },
        { label: 'OR Utilization', href: '/analytics/or-utilization', icon: Gauge },
        { label: 'Primetime Utilization', href: '/analytics/primetime-utilization', icon: Clock },
        { label: 'Room Running', href: '/analytics/room-running', icon: Activity },
        { label: 'Turnover Times', href: '/analytics/turnover-times', icon: Timer },
      ],
    },
    {
      title: 'Ancillary Performance',
      items: [
        { label: 'Radiology TAT', href: '/analytics/radiology-tat', icon: Timer },
        { label: 'IR Suite Utilization', href: '/analytics/ir-utilization', icon: Activity },
      ],
    },
    // P5: per-domain retrospective pages, re-homed from their workspaces —
    // the temporal split ("now" = workspace, "over time" = Study).
    {
      title: 'Capacity Trends',
      items: [
        { label: 'RTDC Utilization', href: '/rtdc/analytics/utilization', icon: Gauge },
        { label: 'RTDC Performance', href: '/rtdc/analytics/performance', icon: LineChart },
        { label: 'RTDC Resources', href: '/rtdc/analytics/resources', icon: Boxes },
        { label: 'RTDC Trends', href: '/rtdc/analytics/trends', icon: TrendingUp },
      ],
    },
    {
      title: 'ED & Transport Trends',
      items: [
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
      title: 'Run & Learn',
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
  matchPrefixes: ['/users', '/admin'],
  groups: [
    {
      title: '',
      items: [
        {
          label: 'Administration Overview',
          href: '/admin',
          icon: LayoutDashboard,
          adminOrCapability: 'view_administration',
        },
        {
          label: 'User Audit',
          href: '/admin/user-audit',
          icon: ScrollText,
          adminOrCapability: 'view_user_audit',
        },
        { label: 'User Management', href: '/users', icon: Users, adminOnly: true },
        // P8 WS-6b — the cockpit threshold editor (band-edge tuning, audited).
        { label: 'Cockpit Thresholds', href: '/admin/cockpit/thresholds', icon: Settings, adminOnly: true },
        {
          label: 'Enterprise Setup',
          href: '/admin/enterprise-setup',
          icon: Building2,
          requiredCapability: 'view_enterprise_setup',
        },
        // NOTE: "Auth Providers" intentionally omitted — admin/auth-providers/{type}
        // is a JSON API endpoint with no Inertia page, so a nav link would break
        // navigation. Re-add once a real OIDC admin Inertia page exists.
      ],
    },
  ],
};

const INTEGRATIONS: NavDomain = {
  key: 'integrations',
  label: 'Integrations',
  icon: PlugZap,
  dashboardHref: '/integrations',
  dashboardLabel: 'Integration Control Plane',
  matchPrefixes: ['/integrations'],
  requiredCapability: 'view_integrations',
  groups: [],
};

/** Primary sections in top-bar order. Admin pages live in the user menu. */
export const NAV_SECTIONS: readonly NavSection[] = [
  {
    key: 'cockpit',
    title: 'Cockpit',
    icon: Gauge,
    homeHref: TOP_LEVEL_DASHBOARD.href,
    domains: [],
  },
  {
    key: 'workspaces',
    title: 'Workspaces',
    icon: LayoutGrid,
    domains: [RTDC, EMERGENCY, PERIOPERATIVE, RADIOLOGY, LAB, TRANSPORT, STAFFING],
  },
  { key: 'study', title: 'Study', icon: BookOpen, domains: [ANALYTICS, IMPROVEMENT] },
  {
    key: 'integrations',
    title: 'Integrations',
    icon: PlugZap,
    homeHref: '/integrations',
    domains: [INTEGRATIONS],
  },
];

/** Flat domain list for palette and ownership; administration is user-menu only. */
export const NAVIGATION: readonly NavDomain[] = [
  ...NAV_SECTIONS.flatMap((section) => section.domains),
  ADMIN,
];

export function isDomainActive(domain: NavDomain, url: string): boolean {
  const path = (url || '').split('?')[0].split('#')[0];
  const hits = (p: string): boolean => path === p || path.startsWith(`${p}/`);
  if (domain.excludePrefixes?.some(hits)) return false;
  return domain.matchPrefixes.some(hits);
}

export function isDomainVisible(domain: NavDomain, access: NavigationAccess): boolean {
  if (domain.adminOnly) return access.isAdmin;
  if (domain.requiredCapability) return access.can?.[domain.requiredCapability] === true;
  if (domain.key === 'admin') {
    return domain.groups.some((group) => group.items.some((item) => isLeafVisible(item, access)));
  }
  return true;
}

export function visibleDomains(access: NavigationAccess): readonly NavDomain[] {
  return NAVIGATION.filter((domain) => isDomainVisible(domain, access));
}

export function isLeafVisible(item: NavLeaf, access: NavigationAccess): boolean {
  if (item.requiredFeature && access.features?.[item.requiredFeature] !== true) return false;
  if (item.adminOnly && !access.isAdmin) return false;
  if (item.adminOrCapability) {
    return access.isAdmin || access.can?.[item.adminOrCapability] === true;
  }
  if (item.requiredCapability) return access.can?.[item.requiredCapability] === true;
  return true;
}

/** Capability-gated sections are removed before rendering any navigation surface. */
export function visibleSections(access: NavigationAccess): readonly NavSection[] {
  return NAV_SECTIONS.map((section) => ({
    ...section,
    domains: section.domains.filter((domain) => isDomainVisible(domain, access)),
  })).filter((section) => {
    if (section.key === 'cockpit') return true;
    return section.domains.length > 0;
  });
}

/** Returns all canonical domain owners for a URL. Tests require this to be 0 or 1. */
export function navigationOwners(url: string): readonly NavDomain[] {
  return NAVIGATION.filter((domain) => isDomainActive(domain, url));
}

export function activeNavigationOwner(url: string): NavDomain | undefined {
  return navigationOwners(url)[0];
}

/** Shared local-navigation projection for workspace layouts. */
export function domainLocalNavigation(
  domainKey: string,
  access: NavigationAccess,
): readonly NavLeaf[] {
  const domain = NAVIGATION.find((candidate) => candidate.key === domainKey);
  if (!domain || !isDomainVisible(domain, access)) return [];
  return domain.groups.flatMap((group) =>
    group.items.filter((item) => isLeafVisible(item, access)),
  );
}

export function isSectionActive(section: NavSection, url: string): boolean {
  const path = (url || '').split('?')[0].split('#')[0];
  if (section.key === 'cockpit') return path === '/' || path === TOP_LEVEL_DASHBOARD.href;
  return section.domains.some((domain) => isDomainActive(domain, path));
}

export interface FlatNavEntry {
  readonly label: string;
  readonly href: string;
  readonly group: string;
}

/** Flatten the config for the command palette, respecting capability gates.
 *  Group items are pushed before each domain's header link so a descriptive
 *  page label wins when dashboardHref points to the same URL.
 */
export function flattenNavigation(access: NavigationAccess): readonly FlatNavEntry[] {
  const seen = new Set<string>();
  const entries: FlatNavEntry[] = [];

  function push(entry: FlatNavEntry): void {
    if (seen.has(entry.href)) return;
    seen.add(entry.href);
    entries.push(entry);
  }

  push({ label: TOP_LEVEL_DASHBOARD.label, href: TOP_LEVEL_DASHBOARD.href, group: 'Navigation' });

  for (const domain of visibleDomains(access)) {
    for (const group of domain.groups) {
      const groupLabel = group.title ? `${domain.label} ${group.title}` : domain.label;
      for (const item of group.items) {
        if (!isLeafVisible(item, access)) continue;
        push({ label: item.label, href: item.href, group: groupLabel });
      }
    }
    if (domain.dashboardHref) {
      push({ label: domain.label, href: domain.dashboardHref, group: 'Navigation' });
    }
  }

  return entries;
}
