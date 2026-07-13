import { describe, it, expect } from 'vitest';
import {
  NAVIGATION,
  NAV_SECTIONS,
  TOP_LEVEL_DASHBOARD,
  isDomainActive,
  visibleDomains,
  visibleSections,
  flattenNavigation,
  navigationOwners,
  domainLocalNavigation,
} from '@/config/navigationConfig';

const USER_ACCESS = { isAdmin: false } as const;
const ADMIN_ACCESS = { isAdmin: true } as const;
const SUPERUSER_ACCESS = {
  isAdmin: false,
  can: {
    view_integrations: true,
    view_enterprise_setup: true,
    manage_staffing_alignment: true,
  },
} as const;
const AUDITOR_ACCESS = {
  isAdmin: false,
  can: {
    view_administration: true,
    view_user_audit: true,
  },
} as const;

describe('navigationConfig', () => {
  it('exposes only section-level top navigation in order', () => {
    expect(NAV_SECTIONS.map((s) => s.key)).toEqual([
      'cockpit',
      'workspaces',
      'study',
      'integrations',
    ]);
    expect(NAV_SECTIONS[0].homeHref).toBe('/dashboard');
    expect(NAV_SECTIONS[0].domains).toEqual([]);
  });

  it('exposes the dropdown domains in section order', () => {
    expect(NAVIGATION.map((d) => d.key)).toEqual([
      'rtdc',
      'emergency',
      'perioperative',
      'radiology',
      'lab',
      'transport',
      'staffing',
      'analytics',
      'improvement',
      'integrations',
      'admin',
    ]);
  });

  it('never links a retired /dashboard/* overview (they are redirects now)', () => {
    for (const domain of NAVIGATION) {
      if (domain.dashboardHref) {
        expect(domain.dashboardHref.startsWith('/dashboard/')).toBe(false);
      }
      for (const group of domain.groups) {
        for (const item of group.items) {
          expect(item.href.startsWith('/dashboard/')).toBe(false);
        }
      }
    }
  });

  it('every workspace domain header points at a live workspace', () => {
    const workspaces = NAV_SECTIONS.find((s) => s.key === 'workspaces')!;
    const hrefs = Object.fromEntries(workspaces.domains.map((d) => [d.key, d.dashboardHref]));
    expect(hrefs).toEqual({
      rtdc: '/rtdc/bed-tracking',
      emergency: '/ed/operations/triage',
      perioperative: '/operations/room-status',
      radiology: '/radiology',
      lab: '/lab',
      transport: '/transport/dispatch',
      staffing: '/staffing',
    });
  });

  it('keeps administration out of the top-level section controls', () => {
    expect(NAV_SECTIONS.flatMap((section) => section.domains).map((domain) => domain.key))
      .not.toContain('admin');
    expect(NAVIGATION.map((domain) => domain.key)).toContain('admin');
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
    // NOTE: /rtdc/predictions/risk is now a LIVE route (web.php → RTDCDashboardController@riskAssessment
    // → RTDC/Predictions/RiskAssessment), so it is intentionally linked and no longer in this list.
    expect(hrefs).not.toContain('/operations/staffing');
    expect(hrefs).not.toContain('/analytics/procedure-analysis');
    expect(hrefs).not.toContain('/predictions/volume-forecasting');
    expect(hrefs).not.toContain('/home');
    expect(hrefs).not.toContain('/transport/settings/integrations');
    expect(hrefs).not.toContain('/deployment');
    expect(hrefs).not.toContain('/deployment/staffing');
  });

  it('matches a domain by URL prefix without cross-domain bleed', () => {
    const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;
    const analytics = NAVIGATION.find((d) => d.key === 'analytics')!;
    const perioperative = NAVIGATION.find((d) => d.key === 'perioperative')!;

    expect(isDomainActive(rtdc, '/rtdc/bed-tracking')).toBe(true);
    // The legacy overview URL redirects server-side — no domain claims it.
    expect(isDomainActive(rtdc, '/dashboard/rtdc')).toBe(false);
    expect(isDomainActive(analytics, '/analytics/or-utilization')).toBe(true);
    // /analytics belongs to the Analytics domain, NOT Perioperative
    expect(isDomainActive(perioperative, '/analytics/or-utilization')).toBe(false);
    expect(isDomainActive(rtdc, '/analytics/or-utilization')).toBe(false);
  });

  it('re-homed trend pages glow Study, not their old workspace (P5)', () => {
    const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;
    const emergency = NAVIGATION.find((d) => d.key === 'emergency')!;
    const transport = NAVIGATION.find((d) => d.key === 'transport')!;
    const analytics = NAVIGATION.find((d) => d.key === 'analytics')!;

    for (const url of [
      '/rtdc/analytics/utilization',
      '/ed/analytics/wait-time',
      '/ed/analytics/resources',
      '/transport/analytics',
    ]) {
      expect(isDomainActive(analytics, url)).toBe(true);
    }
    expect(isDomainActive(rtdc, '/rtdc/analytics/utilization')).toBe(false);
    expect(isDomainActive(emergency, '/ed/analytics/wait-time')).toBe(false);
    expect(isDomainActive(transport, '/transport/analytics')).toBe(false);
    // The live 4D navigator stays an Emergency "now" surface despite its URL.
    expect(isDomainActive(emergency, '/ed/analytics/flow')).toBe(true);
    expect(isDomainActive(analytics, '/ed/analytics/flow')).toBe(false);
    // Non-analytics workspace pages still glow their own domain.
    expect(isDomainActive(rtdc, '/rtdc/bed-tracking')).toBe(true);
    expect(isDomainActive(transport, '/transport/dispatch')).toBe(true);
  });

  it('surgical deep-dives appear exactly once across all nav domains (P5)', () => {
    const surgical = [
      '/analytics/block-utilization',
      '/analytics/or-utilization',
      '/analytics/primetime-utilization',
      '/analytics/room-running',
      '/analytics/turnover-times',
    ];
    const allHrefs = NAVIGATION.flatMap((d) =>
      d.groups.flatMap((g) => g.items.map((i) => i.href)),
    );
    for (const href of surgical) {
      expect(allHrefs.filter((h) => h === href)).toHaveLength(1);
    }
    // ...and their one home is the Study-altitude Analytics domain.
    const analytics = NAVIGATION.find((d) => d.key === 'analytics')!;
    const analyticsHrefs = analytics.groups.flatMap((g) => g.items.map((i) => i.href));
    for (const href of surgical) {
      expect(analyticsHrefs).toContain(href);
    }
  });

  it('per-domain trend pages live only under the Analytics domain (P5)', () => {
    const rehomed = [
      '/rtdc/analytics/utilization',
      '/rtdc/analytics/performance',
      '/rtdc/analytics/resources',
      '/rtdc/analytics/trends',
      '/ed/analytics/wait-time',
      '/ed/analytics/resources',
      '/transport/analytics',
    ];
    const analytics = NAVIGATION.find((d) => d.key === 'analytics')!;
    const analyticsHrefs = analytics.groups.flatMap((g) => g.items.map((i) => i.href));
    const workspaceHrefs = NAV_SECTIONS.find((s) => s.key === 'workspaces')!
      .domains.flatMap((d) => d.groups.flatMap((g) => g.items.map((i) => i.href)));
    for (const href of rehomed) {
      expect(analyticsHrefs).toContain(href);
      expect(workspaceHrefs).not.toContain(href);
    }
  });

  it('organizes Study analytics and improvement into task-oriented groups', () => {
    const study = NAV_SECTIONS.find((section) => section.key === 'study')!;
    const analytics = study.domains.find((domain) => domain.key === 'analytics')!;
    const improvement = study.domains.find((domain) => domain.key === 'improvement')!;

    expect(analytics.groups.map((group) => group.title)).toEqual([
      'Overview',
      'Process Analysis',
      'Planning',
      'Perioperative Performance',
      'Ancillary Performance',
      'Capacity Trends',
      'ED & Transport Trends',
    ]);
    expect(improvement.groups.map((group) => group.title)).toEqual(['Diagnose', 'Run & Learn']);

    for (const domain of study.domains) {
      const leafHrefs = domain.groups.flatMap((group) => group.items.map((item) => item.href));
      expect(leafHrefs).toHaveLength(new Set(leafHrefs).size);
    }
  });

  it('owns the Radiology Study routes only from Analytics', () => {
    for (const href of ['/analytics/radiology-tat', '/analytics/ir-utilization']) {
      const occurrences = NAVIGATION.flatMap((domain) => domain.groups.flatMap((group) => group.items.filter((item) => item.href === href).map(() => domain.key)));

      expect(occurrences).toEqual(['analytics']);
      expect(navigationOwners(href).map((domain) => domain.key)).toEqual(['analytics']);
    }
  });

  it('owns every Radiology workspace leaf only from the Radiology domain', () => {
    const hrefs = ['/radiology', '/radiology/worklist', '/radiology/modality', '/radiology/reads'];
    const radiology = NAVIGATION.find((domain) => domain.key === 'radiology')!;

    expect(radiology.groups.flatMap((group) => group.items.map((item) => item.href))).toEqual(hrefs);
    expect(domainLocalNavigation('radiology', USER_ACCESS).map((item) => item.href)).toEqual(hrefs);

    for (const href of hrefs) {
      const occurrences = NAVIGATION.flatMap((domain) =>
        domain.groups.flatMap((group) =>
          group.items.filter((item) => item.href === href).map(() => domain.key),
        ),
      );
      expect(occurrences, href).toEqual(['radiology']);
      expect(navigationOwners(href).map((domain) => domain.key), href).toEqual(['radiology']);
    }
  });

  it('projects identical Radiology leaves to workspace menus and the command palette', () => {
    const workspaceHrefs = domainLocalNavigation('radiology', USER_ACCESS).map((item) => item.href);
    const paletteHrefs = flattenNavigation(USER_ACCESS)
      .filter((entry) => entry.group === 'Radiology Operations')
      .map((entry) => entry.href);

    expect(paletteHrefs).toEqual(workspaceHrefs);
  });

  it('owns the Laboratory Flow Board from one workspace domain', () => {
    const lab = NAVIGATION.find((domain) => domain.key === 'lab')!;

    expect(domainLocalNavigation('lab', USER_ACCESS).map((item) => item.href)).toEqual(['/lab', '/lab/specimens', '/lab/pending-decisions', '/lab/blood-bank']);
    expect(navigationOwners('/lab').map((domain) => domain.key)).toEqual(['lab']);
    expect(lab.dashboardHref).toBe('/lab');
  });

  it('keeps administration in user-menu/palette projections only', () => {
    expect(visibleDomains(USER_ACCESS).map((d) => d.key)).not.toContain('admin');
    expect(visibleDomains(ADMIN_ACCESS).map((d) => d.key)).toContain('admin');
    expect(visibleSections(ADMIN_ACCESS).map((s) => s.key)).toEqual([
      'cockpit',
      'workspaces',
      'study',
    ]);
  });

  it('projects capability-gated administration pages without adding a top-bar section', () => {
    const labels = flattenNavigation(AUDITOR_ACCESS).map((entry) => entry.label);

    expect(labels).toContain('Administration Overview');
    expect(labels).toContain('User Audit');
    expect(labels).not.toContain('User Management');
    expect(visibleDomains(AUDITOR_ACCESS).map((domain) => domain.key)).toContain('admin');
    expect(visibleSections(AUDITOR_ACCESS).map((section) => section.key)).not.toContain('admin');
  });

  it('gates Integrations from server capability props, never role/admin inference', () => {
    expect(visibleSections(USER_ACCESS).map((s) => s.key)).not.toContain('integrations');
    expect(visibleSections(ADMIN_ACCESS).map((s) => s.key)).not.toContain('integrations');
    expect(visibleSections(SUPERUSER_ACCESS).map((s) => s.key)).toContain('integrations');
    expect(flattenNavigation(SUPERUSER_ACCESS).some((entry) => entry.href === '/integrations')).toBe(true);
    expect(flattenNavigation(ADMIN_ACCESS).some((entry) => entry.href === '/integrations')).toBe(false);
  });

  it('flattens to command-palette entries and drops admin items for non-admins', () => {
    const adminFlat = flattenNavigation(ADMIN_ACCESS);
    const userFlat = flattenNavigation(USER_ACCESS);
    expect(adminFlat.some((e) => e.href === '/users')).toBe(true);
    expect(adminFlat.some((e) => e.href === '/admin')).toBe(true);
    expect(adminFlat.some((e) => e.href === '/admin/user-audit')).toBe(true);
    expect(userFlat.some((e) => e.href === '/users')).toBe(false);
    // Sub-pages are present and grouped by "Domain Group"
    expect(userFlat.some((e) => e.label === 'Bed Tracking' && e.group === 'RTDC Operations')).toBe(true);
    expect(userFlat.some((e) => e.label === 'Dispatch' && e.group === 'Transport Operations')).toBe(true);
    // The one home is present under Navigation.
    expect(
      userFlat.some((e) => e.label === TOP_LEVEL_DASHBOARD.label && e.href === '/dashboard'),
    ).toBe(true);
  });

  it('keeps the descriptive page label when a domain header repoints onto a page', () => {
    // RTDC's header link IS /rtdc/bed-tracking now — the dedup must keep the
    // 'Bed Tracking' page entry, not swallow it into a domain-level entry.
    const flat = flattenNavigation(USER_ACCESS);
    const bedTracking = flat.find((e) => e.href === '/rtdc/bed-tracking');
    expect(bedTracking?.label).toBe('Bed Tracking');
  });

  it('flattenNavigation returns each href at most once', () => {
    const hrefs = flattenNavigation(SUPERUSER_ACCESS).map((e) => e.href);
    expect(hrefs.length).toBe(new Set(hrefs).size);
  });

  it('assigns every configured domain URL to exactly one active owner', () => {
    for (const domain of NAVIGATION) {
      const hrefs = new Set([
        ...(domain.dashboardHref ? [domain.dashboardHref] : []),
        ...domain.groups.flatMap((group) => group.items.map((item) => item.href)),
      ]);

      for (const href of hrefs) {
        expect(navigationOwners(href).map((owner) => owner.key), href).toEqual([domain.key]);
      }
    }
  });

  it('owns Patient Flow 4D only from RTDC', () => {
    const patientFlowHref = '/rtdc/patient-flow-navigator';
    const occurrences = NAVIGATION.flatMap((domain) =>
      domain.groups.flatMap((group) =>
        group.items.filter((item) => item.href === patientFlowHref).map(() => domain.key),
      ),
    );

    expect(occurrences).toEqual(['rtdc']);
    expect(navigationOwners(patientFlowHref).map((domain) => domain.key)).toEqual(['rtdc']);
  });

  it('projects Transport local navigation from the same domain config', () => {
    const hrefs = domainLocalNavigation('transport', USER_ACCESS).map((item) => item.href);
    expect(hrefs).toEqual([
      '/transport/requests',
      '/transport/dispatch',
      '/transport/inpatient',
      '/transport/transfers',
      '/transport/discharge',
      '/transport/ems',
      '/transport/care-transitions',
      '/transport/resources',
    ]);
    expect(hrefs).not.toContain('/transport/settings/integrations');
  });

  it('isDomainActive ignores hash fragments', () => {
    const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;
    expect(isDomainActive(rtdc, '/rtdc#section')).toBe(true);
  });
});
