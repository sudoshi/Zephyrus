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
