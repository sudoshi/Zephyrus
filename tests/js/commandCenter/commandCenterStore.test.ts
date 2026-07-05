// tests/js/commandCenter/commandCenterStore.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { useCommandCenterStore, roleFromUrl } from '@/stores/commandCenterStore';

describe('commandCenterStore role persistence', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/dashboard');
    useCommandCenterStore.setState({ role: 'command', serviceLine: null });
  });

  it('reflects a selected role into the URL query for deep-linking', () => {
    useCommandCenterStore.getState().setRole('executive');
    expect(useCommandCenterStore.getState().role).toBe('executive');
    expect(window.location.search).toContain('role=executive');
  });

  it('drops the role param when returning to the command default', () => {
    useCommandCenterStore.getState().setRole('executive');
    useCommandCenterStore.getState().setRole('command');
    expect(window.location.search).not.toContain('role=');
  });

  it('seeds role from a ?role= deep link', () => {
    window.history.replaceState({}, '', '/dashboard?role=executive');
    expect(roleFromUrl()).toBe('executive');
  });

  it('ignores service-line in the URL (it is a scope, not a selectable persona)', () => {
    window.history.replaceState({}, '', '/dashboard?role=service-line');
    expect(roleFromUrl()).toBe('command');
  });

  it('ignores an unknown role in the URL', () => {
    window.history.replaceState({}, '', '/dashboard?role=bogus');
    expect(roleFromUrl()).toBe('command');
  });

  it('defaults to command with no role param', () => {
    window.history.replaceState({}, '', '/dashboard');
    expect(roleFromUrl()).toBe('command');
  });
});
