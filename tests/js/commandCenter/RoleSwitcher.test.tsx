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
});
