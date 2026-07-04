import { describe, it, expect } from 'vitest';
import {
  NAVIGATION,
  NAV_SECTIONS,
  TOP_LEVEL_DASHBOARD,
  isDomainActive,
  visibleDomains,
  visibleSections,
  flattenNavigation,
} from '@/config/navigationConfig';

describe('navigationConfig', () => {
  it('exposes the four altitude sections in order (P4a)', () => {
    expect(NAV_SECTIONS.map((s) => s.key)).toEqual(['cockpit', 'workspaces', 'study', 'admin']);
    // COCKPIT is the one home: a plain link, no dropdown domains.
    expect(NAV_SECTIONS[0].homeHref).toBe('/dashboard');
    expect(NAV_SECTIONS[0].domains).toEqual([]);
  });

  it('exposes the dropdown domains in section order', () => {
    expect(NAVIGATION.map((d) => d.key)).toEqual([
      'rtdc',
      'emergency',
      'perioperative',
      'transport',
      'staffing',
      'flow',
      'analytics',
      'improvement',
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
      transport: '/transport/dispatch',
      staffing: '/staffing',
      flow: '/rtdc/patient-flow-navigator',
    });
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
    // NOTE: /rtdc/predictions/risk is now a LIVE route (web.php → RTDCDashboardController@riskAssessment
    // → RTDC/Predictions/RiskAssessment), so it is intentionally linked and no longer in this list.
    expect(hrefs).not.toContain('/operations/staffing');
    expect(hrefs).not.toContain('/analytics/procedure-analysis');
    expect(hrefs).not.toContain('/predictions/volume-forecasting');
    expect(hrefs).not.toContain('/home');
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

  it('hides the admin domain and section for non-admins', () => {
    expect(visibleDomains(false).map((d) => d.key)).not.toContain('admin');
    expect(visibleDomains(true).map((d) => d.key)).toContain('admin');
    expect(visibleSections(false).map((s) => s.key)).toEqual(['cockpit', 'workspaces', 'study']);
    expect(visibleSections(true).map((s) => s.key)).toEqual([
      'cockpit',
      'workspaces',
      'study',
      'admin',
    ]);
  });

  it('flattens to command-palette entries and drops admin items for non-admins', () => {
    const adminFlat = flattenNavigation(true);
    const userFlat = flattenNavigation(false);
    expect(adminFlat.some((e) => e.href === '/users')).toBe(true);
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
    const flat = flattenNavigation(false);
    const bedTracking = flat.find((e) => e.href === '/rtdc/bed-tracking');
    expect(bedTracking?.label).toBe('Bed Tracking');
  });

  it('flattenNavigation returns each href at most once', () => {
    const hrefs = flattenNavigation(true).map((e) => e.href);
    expect(hrefs.length).toBe(new Set(hrefs).size);
  });

  it('isDomainActive ignores hash fragments', () => {
    const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;
    expect(isDomainActive(rtdc, '/rtdc#section')).toBe(true);
  });
});
