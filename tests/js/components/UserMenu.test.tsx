import { describe, it, expect } from 'vitest';
import { getUserMenuItems } from '@/Components/Navigation/UserMenu';

describe('getUserMenuItems', () => {
  it('shows Profile and Logout for a normal user, no User Management', () => {
    const labels = getUserMenuItems({ isAdmin: false }).map((i) => i.label);
    expect(labels).toEqual(['Profile', 'Logout']);
  });

  it('moves general administration links into the admin user menu', () => {
    const labels = getUserMenuItems({ isAdmin: true }).map((i) => i.label);
    expect(labels).toEqual(['Profile', 'User Management', 'Cockpit Thresholds', 'Logout']);
  });

  it('shows Enterprise Setup only from its server capability', () => {
    const labels = getUserMenuItems({
      isAdmin: false,
      can: { view_enterprise_setup: true },
    }).map((i) => i.label);
    expect(labels).toEqual(['Profile', 'Enterprise Setup', 'Logout']);
  });

  it('marks Logout as an action, not a link', () => {
    const logout = getUserMenuItems({ isAdmin: false }).find((i) => i.label === 'Logout')!;
    expect(logout.action).toBe('logout');
    expect(logout.href).toBeUndefined();
  });
});
