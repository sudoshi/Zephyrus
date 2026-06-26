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
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />);
    expect(screen.getByRole('heading', { name: 'Capacity' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Flow' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Outcomes' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Forecast' })).toBeInTheDocument();
  });

  it('calls onRefresh when the refresh button is clicked', () => {
    const onRefresh = vi.fn();
    render(<CommandCenterView data={commandCenterFixture} onRefresh={onRefresh} updatedLabel="just now" />);
    fireEvent.click(screen.getByRole('button', { name: /refresh data/i }));
    expect(onRefresh).toHaveBeenCalledTimes(1);
  });

  it('shows the OKR scoreboard when role is executive', () => {
    useCommandCenterStore.setState({ role: 'executive', serviceLine: null });
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />);
    expect(screen.getByLabelText('OKR scoreboard')).toBeInTheDocument();
  });

  it('shows the unit heat strip in command view', () => {
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />);
    expect(screen.getByLabelText('Unit census heat map')).toBeInTheDocument();
  });

  it('suppresses unit-level census noise in the executive view', () => {
    useCommandCenterStore.setState({ role: 'executive', serviceLine: null });
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />);
    expect(screen.queryByLabelText('Unit census heat map')).not.toBeInTheDocument();
  });

  it('collapses tile detail in command (glance) and expands it in executive (review)', () => {
    const { rerender } = render(
      <CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />,
    );
    expect(screen.queryByTestId('sparkline-readmission')).not.toBeInTheDocument();

    useCommandCenterStore.setState({ role: 'executive', serviceLine: null });
    rerender(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />);
    expect(screen.getByTestId('sparkline-readmission')).toBeInTheDocument();
  });

  it('re-levels band order by role (executive leads with Outcomes, command with Capacity)', () => {
    const { rerender } = render(
      <CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />,
    );
    const commandOrder = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
    expect(commandOrder[0]).toBe('Capacity');

    useCommandCenterStore.setState({ role: 'executive', serviceLine: null });
    rerender(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />);
    const execOrder = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
    expect(execOrder[0]).toBe('Outcomes');
  });

  it('surfaces the updated label honestly', () => {
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="3 min ago" />);
    expect(screen.getByText('Updated 3 min ago')).toBeInTheDocument();
  });

  it('shows a stale live-region banner with a retry when data stops advancing', () => {
    const onRefresh = vi.fn();
    render(
      <CommandCenterView data={commandCenterFixture} onRefresh={onRefresh} updatedLabel="4 min ago" stale />,
    );
    const banner = screen.getByRole('status', { name: /stale data warning/i });
    expect(banner).toHaveTextContent(/Live updates interrupted/i);
    expect(banner).toHaveTextContent('4 min ago');
    fireEvent.click(screen.getByRole('button', { name: /retry now/i }));
    expect(onRefresh).toHaveBeenCalledTimes(1);
  });

  it('does not show the stale banner when data is fresh', () => {
    render(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />);
    expect(screen.queryByRole('status', { name: /stale data warning/i })).not.toBeInTheDocument();
  });

  it('disables the refresh control while a refresh is in flight', () => {
    render(
      <CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" refreshing />,
    );
    expect(screen.getByRole('button', { name: /refresh data/i })).toBeDisabled();
    expect(screen.getByText('Refreshing…')).toBeInTheDocument();
  });

  it('shows an AA-safe amber freshness dot when data is aging (not low-contrast amber text)', () => {
    const { container, rerender } = render(
      <CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="1 min ago" aging />,
    );
    expect(screen.getByText(/Updated 1 min ago/)).toBeInTheDocument();
    expect(container.querySelector('[class*="bg-healthcare-warning"]')).toBeInTheDocument();
    rerender(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />);
    expect(container.querySelector('[class*="bg-healthcare-warning"]')).not.toBeInTheDocument();
  });

  it('announces recovery only on the stale → fresh transition, not on routine refresh', () => {
    const { rerender } = render(
      <CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="3 min ago" stale />,
    );
    const announcer = screen.getByRole('status', { name: /live update status/i });
    expect(announcer).toHaveTextContent(''); // silent while stale and on first paint
    rerender(<CommandCenterView data={commandCenterFixture} onRefresh={() => {}} updatedLabel="just now" />);
    expect(announcer).toHaveTextContent(/Live updates resumed/i);
  });
});
