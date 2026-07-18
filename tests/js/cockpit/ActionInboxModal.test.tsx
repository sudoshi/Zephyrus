// tests/js/cockpit/ActionInboxModal.test.tsx
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { ActionInboxModal } from '@/Components/cockpit/ActionInboxModal';

const mockInbox = vi.fn();
const mockMutate = vi.fn();

vi.mock('@/features/ops/hooks', () => ({
  useAgentInbox: () => mockInbox(),
  useDecideApproval: () => ({ mutate: mockMutate, isPending: false }),
}));

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, className }: { href: string; children: React.ReactNode; className?: string }) => (
    <a href={href} className={className}>{children}</a>
  ),
}));

const inboxData = {
  summary: { pendingApprovals: 1, activeActions: 1, approvedActions: 0, assignedActions: 0, executingActions: 0, overdueActions: 0 },
  approvals: [{
    approvalId: 42,
    status: 'pending',
    reason: null,
    action: { actionId: 7, type: 'propose_surge_plan', status: 'draft', ownerName: null, recommendation: { title: 'Open the surge unit', riskLevel: 'critical' } },
  }],
  actions: [{ actionId: 7, type: 'propose_surge_plan', status: 'approved', ownerName: null, isOverdue: false, recommendation: { title: 'Open the surge unit', riskLevel: 'critical' } }],
};

describe('ActionInboxModal', () => {
  beforeEach(() => {
    mockMutate.mockClear();
    mockInbox.mockReturnValue({ data: inboxData, isLoading: false });
  });

  it('renders nothing when closed', () => {
    render(<ActionInboxModal open={false} onClose={() => {}} />);
    expect(screen.queryByTestId('cockpit-action-inbox')).toBeNull();
  });

  it('lists pending approvals with the WS-6 severity glyph and decides through the FSM endpoint', () => {
    render(<ActionInboxModal open onClose={() => {}} />);

    expect(screen.getAllByText('Open the surge unit').length).toBeGreaterThan(0);
    // critical risk → crit ◆ via the one riskStatus mapping.
    expect(screen.getByRole('img', { name: 'Critical' })).toBeInTheDocument();

    // HFE Phase 1: no one-click inline decision — Approve/Reject only exist
    // inside the expanded review evidence sheet.
    expect(screen.queryByRole('button', { name: /^Approve/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Review: Open the surge unit' }));

    // The evidence sheet is visible before any decision control.
    expect(screen.getByText('Rationale')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Approve: Open the surge unit' }));
    expect(mockMutate).toHaveBeenCalledWith({ approvalId: 42, decision: 'approved' });

    // Deciding collapses the review; re-open it to reject.
    fireEvent.click(screen.getByRole('button', { name: 'Review: Open the surge unit' }));
    fireEvent.click(screen.getByRole('button', { name: 'Reject: Open the surge unit' }));
    expect(mockMutate).toHaveBeenCalledWith({ approvalId: 42, decision: 'rejected' });
  });

  it('keeps the standalone agent inbox as a deep-link and closes on Escape', () => {
    const onClose = vi.fn();
    render(<ActionInboxModal open onClose={onClose} />);

    expect(screen.getByRole('link', { name: /full agent inbox/i })).toHaveAttribute('href', '/ops/agent-inbox');

    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onClose).toHaveBeenCalled();
  });
});
