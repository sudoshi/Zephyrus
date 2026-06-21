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
