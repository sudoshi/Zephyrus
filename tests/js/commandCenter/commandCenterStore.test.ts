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

  it('ignores a non-selectable role in the URL (service-line not yet live)', () => {
    window.history.replaceState({}, '', '/dashboard?role=service-line');
    expect(roleFromUrl()).toBe('command');
  });

  it('defaults to command with no role param', () => {
    window.history.replaceState({}, '', '/dashboard');
    expect(roleFromUrl()).toBe('command');
  });
});
