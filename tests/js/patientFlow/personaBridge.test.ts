import { describe, expect, it } from 'vitest';
import { commandRoleForLens, navigatorUrlForRole } from '@/features/patientFlowNavigator/personaBridge';

/**
 * F-1 ruling (audit 2026-07-19): the RoleSwitcher and the server flow lens
 * are ONE canonical persona state on the navigator page. These helpers pin
 * the mapping and the transition URL.
 */

describe('commandRoleForLens', () => {
  it('maps the executive lens to the Executive tab', () => {
    expect(commandRoleForLens('executive')).toBe('executive');
  });

  it('maps every non-executive lens (and no lens) to Command', () => {
    expect(commandRoleForLens(null)).toBe('command');
    expect(commandRoleForLens(undefined)).toBe('command');
    expect(commandRoleForLens('charge_nurse')).toBe('command');
    expect(commandRoleForLens('house_supervisor')).toBe('command');
  });
});

describe('navigatorUrlForRole', () => {
  const base = 'https://zephyrus.test/rtdc/patient-flow-navigator';

  it('performs the executive transition via ?persona=', () => {
    expect(navigatorUrlForRole(base, 'executive'))
      .toBe('/rtdc/patient-flow-navigator?persona=executive');
  });

  it('drops persona for the Command (default lens) transition', () => {
    expect(navigatorUrlForRole(`${base}?persona=executive`, 'command'))
      .toBe('/rtdc/patient-flow-navigator');
  });

  it('preserves unrelated params and strips the client-only ?role=', () => {
    const href = `${base}?scope=unit%3A7&role=executive&focus_stop=abc`;
    const url = navigatorUrlForRole(href, 'executive');
    expect(url).toContain('persona=executive');
    expect(url).toContain('scope=unit%3A7');
    expect(url).toContain('focus_stop=abc');
    expect(url).not.toContain('role=');
  });
});
