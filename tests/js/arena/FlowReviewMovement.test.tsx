// tests/js/arena/FlowReviewMovement.test.tsx
//
// Smoke test for the 48-Hour Flow Review movement: it parses the artifact (here
// the fixture, since the /review endpoint 404s until the backend loop ships),
// ranks barriers worst-first, drives one selection atom across the rail and the
// corrective-action panel, and renders the deviant cases the Study drops.
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { FlowReviewMovement } from '@/Components/arena/review/FlowReviewMovement';

// Each barrier legitimately has two affordances — a chronobar marker and a rail
// row — so scope row queries to the rail (its heading's container) to target one.
function rail() {
  return within(screen.getByRole('heading', { name: /Barriers this window/ }).parentElement as HTMLElement);
}

// The /review query is left pending so the movement takes its fixture fallback —
// the same path a not-yet-built endpoint produces in the app.
vi.mock('@/features/arena/api', () => ({
  fetchArenaReview: vi.fn(() => new Promise(() => {})),
  runArenaReview: vi.fn(),
  fetchArenaMap: vi.fn(),
  fetchArenaSummary: vi.fn(),
  fetchArenaConformance: vi.fn(),
  fetchArenaPerformance: vi.fn(),
  fetchArenaNarrative: vi.fn(),
}));

// reactflow needs real layout; stub the map so the test targets the movement's
// composition/selection logic, not the (already-shipped) OcdfgMap.
vi.mock('@/Components/arena/review/ReviewFlowMap', () => ({
  ReviewFlowMap: () => null,
}));

function renderMovement() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <FlowReviewMovement aiEnabled canApprove />
    </QueryClientProvider>,
  );
}

describe('FlowReviewMovement', () => {
  it('renders the fixture: summary, ranked barriers, and the worst barrier as default action', () => {
    renderMovement();

    // Summary strip
    expect(screen.getByText('Open barriers')).toBeInTheDocument();

    // All three unified-taxonomy barriers render in the rail.
    expect(rail().getByRole('button', { name: /Bed-assign → transport hand-off breaching/ })).toBeInTheDocument();
    expect(rail().getByRole('button', { name: /Sepsis antibiotic late vs SEP-3/ })).toBeInTheDocument();
    expect(rail().getByRole('button', { name: /Isolation bed shortage/ })).toBeInTheDocument();

    // Worst-first: the critical flow barrier is the default selection, so its
    // pending draft shows in the corrective-action panel.
    expect(screen.getByText(/Pre-page transport on bed_assigned/)).toBeInTheDocument();
    expect(screen.getByText('PENDING APPROVAL')).toBeInTheDocument();
  });

  it('selection atom: clicking the care barrier swaps the action panel and reveals its deviant cases', () => {
    renderMovement();

    fireEvent.click(rail().getByRole('button', { name: /Sepsis antibiotic late vs SEP-3/ }));

    // Panel now shows the sepsis draft.
    expect(screen.getByText(/Sepsis order-set: abx pre-selected at triage/)).toBeInTheDocument();

    // The deviant cases the Study fetches-then-drops are now reachable.
    const casesButton = screen.getByRole('button', { name: /View 3 deviant cases/ });
    fireEvent.click(casesButton);
    expect(screen.getByText('enc-3d9f21')).toBeInTheDocument();
  });
});
