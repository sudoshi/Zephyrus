// tests/js/commandCenter/CommandCenterView.test.tsx
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CommandCenterView } from '@/Components/CommandCenter/CommandCenterView';
import { useCommandCenterStore } from '@/stores/commandCenterStore';
import { commandCenterFixture } from './fixture';

describe('CommandCenterView', () => {
  beforeEach(() => {
    useCommandCenterStore.setState({ role: 'command', serviceLine: null });
  });

  it('renders all four band titles', () => {
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} refreshedLabel="just now" />);
    expect(screen.getByRole('heading', { name: 'Capacity' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Flow' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Outcomes' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Forecast' })).toBeInTheDocument();
  });

  it('calls onRefresh when the refresh button is clicked', () => {
    const onRefresh = vi.fn();
    render(<CommandCenterView data={commandCenterFixture} onRefresh={onRefresh} refreshedLabel="just now" />);
    fireEvent.click(screen.getByRole('button', { name: /refresh/i }));
    expect(onRefresh).toHaveBeenCalledTimes(1);
  });

  it('shows the OKR scoreboard when role is executive', () => {
    useCommandCenterStore.setState({ role: 'executive', serviceLine: null });
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} refreshedLabel="just now" />);
    expect(screen.getByLabelText('OKR scoreboard')).toBeInTheDocument();
  });
});
