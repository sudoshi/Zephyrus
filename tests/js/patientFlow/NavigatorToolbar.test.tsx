import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NavigatorToolbar from '@/Components/PatientFlowNavigator/NavigatorToolbar';
import type { LayerControl } from '@/Components/PatientFlowNavigator/NavigatorToolbar';
import type { OccupancySummary } from '@/features/patientFlowNavigator/types';

/**
 * Phase 0 barrier-toggle disambiguation (plan §3): "Delayed only" is a census
 * scope in the filter grid, the "Barriers" layer keeps its own switch, an
 * active scope announces itself with a chip, and the camera never moves as a
 * checkbox side effect (Focus is an explicit action).
 */

const occupancy: OccupancySummary = {
  active: 0,
  delayed: 0,
  watch: 0,
  transportDelays: 0,
  evsDelays: 0,
  readyToMove: 0,
  avgStayMinutes: 0,
  serviceLines: [],
  persona: { transport: 0, evs: 0, bedManager: 0, capacity: 0 },
  topBarriers: [],
};

const layerControls: LayerControl[] = [
  { key: 'base', label: 'Model', id: 'flow-layer-model' },
  { key: 'heat', label: 'Census', id: 'flow-layer-census' },
  {
    key: 'barriers',
    label: 'Barriers',
    id: 'flow-layer-barriers',
    title: 'Logged operational barriers (diamond markers)',
  },
];

function renderToolbar(overrides: Partial<React.ComponentProps<typeof NavigatorToolbar>> = {}) {
  const handlers = {
    onTogglePlay: vi.fn(),
    onToggleLive: vi.fn(),
    onResetCamera: vi.fn(),
    onFocusPatients: vi.fn(),
    onFocusDelayed: vi.fn(),
    onAskEddy: vi.fn(),
    onSpeedChange: vi.fn(),
    onFiltersChange: vi.fn(),
    onFloorSelect: vi.fn(),
    onLayerChange: vi.fn(),
    onBarrierFinderChange: vi.fn(),
    onSearchSubmit: vi.fn(),
    onSaveView: vi.fn(),
    onApplyView: vi.fn(),
  };

  render(
    <NavigatorToolbar
      summary={null}
      ambient={null}
      lensTitle={null}
      chronobar={<div />}
      playing={false}
      live={false}
      speed={60}
      filters={{ floor: 'all', serviceLine: 'all', category: 'all', search: '' }}
      floors={[]}
      services={[]}
      categories={[]}
      layers={{ base: true, tokens: true, trails: true, heat: true, ghosts: true, barriers: true, rounds: true }}
      layerControls={layerControls}
      barrierFinder={false}
      metrics={{ active: 12, events: 40, occupiedLocations: 9 }}
      occupancy={occupancy}
      eddyEnabled={false}
      searchMatches={null}
      savedViews={[false, false, false]}
      {...handlers}
      {...overrides}
    />,
  );

  return handlers;
}

describe('NavigatorToolbar census scope (B-1/B-2)', () => {
  it('offers Census: All | Delayed as a filter, not a layer — "Find barriers" is gone', () => {
    const handlers = renderToolbar();

    expect(screen.queryByLabelText('Find barriers')).not.toBeInTheDocument();

    const scope = screen.getByRole('radiogroup', { name: 'Census scope' });
    expect(scope).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'All' })).toBeChecked();

    fireEvent.click(screen.getByRole('radio', { name: 'Delayed' }));
    expect(handlers.onBarrierFinderChange).toHaveBeenCalledWith(true);
  });

  it('keeps the Barriers layer switch with its disambiguating tooltip', () => {
    renderToolbar();

    const barrierLayer = screen.getByRole('switch', { name: 'Barriers' });
    expect(barrierLayer).toBeInTheDocument();
    expect(screen.getByText('Barriers')).toHaveAttribute(
      'title',
      'Logged operational barriers (diamond markers)',
    );
  });
});

describe('NavigatorToolbar delayed-only state (B-3/B-4)', () => {
  it('shows the filter chip with the count and relabels Active → Delayed', () => {
    renderToolbar({ barrierFinder: true });

    expect(screen.getByText('Filtered: delayed locations only (12)')).toBeInTheDocument();
    // The metric cell carrying the filtered count is labeled Delayed, not Active.
    expect(screen.getByText('12').nextElementSibling).toHaveTextContent('Delayed');
    expect(screen.queryByText('Active')).not.toBeInTheDocument();
  });

  it('flies the camera only via the explicit Focus action', () => {
    const handlers = renderToolbar({ barrierFinder: true });

    expect(handlers.onFocusDelayed).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Focus' }));
    expect(handlers.onFocusDelayed).toHaveBeenCalledTimes(1);
  });

  it('dismissing the chip clears the delayed-only scope', () => {
    const handlers = renderToolbar({ barrierFinder: true });

    fireEvent.click(screen.getByRole('button', { name: 'Clear delayed-only filter' }));
    expect(handlers.onBarrierFinderChange).toHaveBeenCalledWith(false);
  });

  it('renders no chip and the plain Active metric when unfiltered', () => {
    renderToolbar();

    expect(screen.queryByText(/Filtered: delayed locations only/)).not.toBeInTheDocument();
    expect(screen.getByText('Active')).toBeInTheDocument();
  });
});

describe('NavigatorToolbar search (N-5)', () => {
  it('shows the match count and flies to matches on Enter', () => {
    const handlers = renderToolbar({
      filters: { floor: 'all', serviceLine: 'all', category: 'all', search: 'PT-4' },
      searchMatches: 3,
    });

    expect(screen.getByText('3 matches · Enter flies to them')).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole('searchbox'), { key: 'Enter' });
    expect(handlers.onSearchSubmit).toHaveBeenCalledTimes(1);
  });

  it('offers a spelling/floor hint on zero matches and clears on Escape', () => {
    const handlers = renderToolbar({
      filters: { floor: 'all', serviceLine: 'all', category: 'all', search: 'zzz' },
      searchMatches: 0,
    });

    expect(screen.getByText('0 matches — check spelling or floor filter')).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole('searchbox'), { key: 'Escape' });
    expect(handlers.onFiltersChange).toHaveBeenCalledWith({ search: '' });
  });
});

describe('NavigatorToolbar saved views (N-7)', () => {
  it('restores only filled slots and saves to any slot', () => {
    const handlers = renderToolbar({ savedViews: [true, false, false] });

    fireEvent.click(screen.getByRole('button', { name: 'View 1' }));
    expect(handlers.onApplyView).toHaveBeenCalledWith(0);

    expect(screen.getByRole('button', { name: 'View 2' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Save current view to slot 3' }));
    expect(handlers.onSaveView).toHaveBeenCalledWith(2);
  });
});

describe('NavigatorToolbar floor select (N-4)', () => {
  it('routes the dropdown through the frame-the-floor path', () => {
    const handlers = renderToolbar({ floors: ['1', '2'] });

    fireEvent.change(screen.getByLabelText('Floor'), { target: { value: '2' } });
    expect(handlers.onFloorSelect).toHaveBeenCalledWith('2');
    expect(handlers.onFiltersChange).not.toHaveBeenCalled();
  });
});

describe('NavigatorToolbar replay labeling (N-8)', () => {
  it('names the stream button as a stored replay, never live', () => {
    renderToolbar();

    const replayButton = screen.getByRole('button', { name: 'Stream stored replay' });
    expect(replayButton).toHaveAttribute('aria-pressed', 'false');
    expect(replayButton).toHaveAttribute('title', 'Stream stored replay (not a live feed)');
  });
});
