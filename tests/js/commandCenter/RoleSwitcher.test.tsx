// tests/js/commandCenter/RoleSwitcher.test.tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { RoleSwitcher } from '@/Components/CommandCenter/RoleSwitcher';
import { useCommandCenterStore } from '@/stores/commandCenterStore';

describe('RoleSwitcher', () => {
  beforeEach(() => {
    useCommandCenterStore.setState({ role: 'command', serviceLine: null });
  });

  it('marks the current role as selected', () => {
    render(<RoleSwitcher />);
    expect(screen.getByRole('tab', { name: 'Command' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tab', { name: 'Executive' })).toHaveAttribute('aria-selected', 'false');
  });

  it('switches role on click and updates the store', () => {
    render(<RoleSwitcher />);
    fireEvent.click(screen.getByRole('tab', { name: 'Executive' }));
    expect(useCommandCenterStore.getState().role).toBe('executive');
    expect(screen.getByRole('tab', { name: 'Executive' })).toHaveAttribute('aria-selected', 'true');
  });

  it('keeps the service-line tab reserved/disabled (it is a scope, not a persona)', () => {
    render(<RoleSwitcher />);
    const serviceLine = screen.getByRole('tab', { name: /Service Line/ });
    expect(serviceLine).toBeDisabled();
    expect(serviceLine).toHaveAttribute('aria-disabled', 'true');
    fireEvent.click(serviceLine);
    expect(useCommandCenterStore.getState().role).toBe('command'); // click is a no-op
  });

  it('applies a roving tabindex (only the active tab is tabbable)', () => {
    render(<RoleSwitcher />);
    expect(screen.getByRole('tab', { name: 'Command' })).toHaveAttribute('tabindex', '0');
    expect(screen.getByRole('tab', { name: 'Executive' })).toHaveAttribute('tabindex', '-1');
  });

  it('moves selection with arrow keys, wrapping past the disabled tab', () => {
    render(<RoleSwitcher />);
    const tablist = screen.getByRole('tablist');
    fireEvent.keyDown(tablist, { key: 'ArrowRight' });
    expect(useCommandCenterStore.getState().role).toBe('executive');
    // ArrowRight again wraps back to command, skipping the disabled service-line.
    fireEvent.keyDown(tablist, { key: 'ArrowRight' });
    expect(useCommandCenterStore.getState().role).toBe('command');
  });
});
