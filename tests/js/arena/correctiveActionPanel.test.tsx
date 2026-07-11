// tests/js/arena/correctiveActionPanel.test.tsx
//
// The corrective-action panel is where the closed loop becomes operable from the
// Review: Approve posts the governed decision (the P3 executor materializes the
// PDSA), and Draft raises a governed correction targeting the barrier. These pin
// the two wires — the right endpoint, the right ids — without a backend.
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { CorrectiveActionPanel } from '@/Components/arena/review/CorrectiveActionPanel';
import type { RankedBarrier } from '@/features/arena/reviewSchema';

vi.mock('@/features/arena/api', () => ({
  postArenaApproveAction: vi.fn(() => Promise.resolve({})),
  postArenaDraftPdsa: vi.fn(() => Promise.resolve({})),
  postArenaDraftCorrection: vi.fn(() => Promise.resolve({})),
}));

import { postArenaApproveAction, postArenaDraftCorrection, postArenaDraftPdsa } from '@/features/arena/api';

const base: RankedBarrier = {
  id: 'flow-assign_bed-transport',
  kind: 'flow',
  severity: 'critical',
  title: 'Bed-assign → transport hand-off breaching',
  subtitle: '4W · median 4.6h',
  location: { unit_id: 41, unit_label: '4 West' },
  encounter_ref: null,
  metric: { value_label: '4.6h', value_sec: 16560, delta_pct: 38, direction: 'up' },
  provenance: { source: 'arena.performance', note: 'sync-wait · observed' },
  map_focus: { node_ids: ['assign_bed', 'transport'], edge_ids: ['assign_bed transport'] },
};

function renderPanel(barrier: RankedBarrier, props: { canApprove?: boolean } = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <CorrectiveActionPanel barrier={barrier} aiEnabled canApprove={props.canApprove ?? true} />
    </QueryClientProvider>,
  );
}

describe('CorrectiveActionPanel', () => {
  it('Approve posts the pending approval id to the ops decision endpoint', async () => {
    renderPanel({
      ...base,
      corrective_action: {
        draft: { action_uuid: 'u', action_type: 'propose_pdsa_cycle', status: 'pending', approved: false, approval_id: 42 },
        prior_outcome: null,
      },
    });

    fireEvent.click(screen.getByRole('button', { name: /Approve & open PDSA/ }));

    await waitFor(() => expect(postArenaApproveAction).toHaveBeenCalledWith(42, 'approved'));
  });

  it('disables Approve without ops:approve', () => {
    renderPanel(
      {
        ...base,
        corrective_action: {
          draft: { action_uuid: 'u', action_type: 'propose_pdsa_cycle', status: 'pending', approved: false, approval_id: 42 },
          prior_outcome: null,
        },
      },
      { canApprove: false },
    );

    expect(screen.getByRole('button', { name: /Approve & open PDSA/ })).toBeDisabled();
  });

  it('Draft raises a bottleneck PDSA targeting the flow barrier when there is no draft yet', async () => {
    renderPanel({ ...base, corrective_action: undefined });

    fireEvent.click(screen.getByRole('button', { name: /Draft corrective action/ }));

    await waitFor(() =>
      expect(postArenaDraftPdsa).toHaveBeenCalledWith('bottleneck', { target_ref: 'flow-assign_bed-transport' }),
    );
  });

  it('Draft raises a pathway correction for a care barrier', async () => {
    renderPanel({ ...base, id: 'care-sepsis', kind: 'care', title: 'Sepsis late', corrective_action: undefined });

    fireEvent.click(screen.getByRole('button', { name: /Draft corrective action/ }));

    await waitFor(() =>
      expect(postArenaDraftCorrection).toHaveBeenCalledWith('sepsis', { target_ref: 'care-sepsis' }),
    );
  });

  it('remounts on barrier change so approve state never leaks (the movement key)', async () => {
    // Mirrors FlowReviewMovement's `key={barrier.id}`: changing barrier remounts
    // the panel, resetting the mutation. Without the key, barrier #2 would show a
    // stale "Approved ✓" and a disabled button (the bug this guards).
    function Harness() {
      const [id, setId] = useState('flow-1');
      const barrier: RankedBarrier = {
        ...base,
        id,
        corrective_action: {
          draft: { action_uuid: 'u', action_type: 'propose_pdsa_cycle', status: 'pending', approved: false, approval_id: id === 'flow-1' ? 1 : 2 },
          prior_outcome: null,
        },
      };
      return (
        <>
          <button type="button" onClick={() => setId('flow-2')}>next</button>
          <CorrectiveActionPanel key={id} barrier={barrier} aiEnabled canApprove />
        </>
      );
    }
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <Harness />
      </QueryClientProvider>,
    );

    fireEvent.click(screen.getByRole('button', { name: /Approve & open PDSA/ }));
    await waitFor(() => expect(screen.getByRole('button', { name: /Approved ✓/ })).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: 'next' }));

    const approve = screen.getByRole('button', { name: /Approve & open PDSA/ });
    expect(approve).toBeEnabled(); // fresh state for barrier #2, not the leaked "Approved ✓"
  });

  it('offers no Draft for a human barrier (resolved operationally)', () => {
    renderPanel({ ...base, id: 'human-77', kind: 'human', title: 'Isolation bed shortage', corrective_action: undefined });

    expect(screen.queryByRole('button', { name: /Draft corrective action/ })).toBeNull();
  });
});
