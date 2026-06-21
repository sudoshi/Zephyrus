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
