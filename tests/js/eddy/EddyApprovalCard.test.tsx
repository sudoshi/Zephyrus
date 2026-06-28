import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, fireEvent, waitFor } from '@testing-library/react';
import { EddyApprovalCard } from '@/Components/Eddy/EddyApprovalCard';
import { useEddyStore, type EddyChatMessage } from '@/stores/eddyStore';

vi.mock('@/features/eddy/api', () => ({
  proposeEddyAction: vi.fn().mockResolvedValue({ action_uuid: 'u', approval_id: 1, status: 'approved', approved: true, tier: 'T1' }),
}));
import { proposeEddyAction } from '@/features/eddy/api';

const message: EddyChatMessage = {
  id: 'a1',
  role: 'assistant',
  content: 'Imaging delays are holding 3W discharges.',
  proposedAction: {
    action_type: 'flag_barrier',
    title: 'Imaging delay on 3W',
    params: { unit: '3W', barrier: 'imaging' },
    rationale: 'Two discharges held on pending CT reads.',
    runner_up: 'Escalate to the radiology charge.',
    tier: 'T1',
    risk: 'low',
    label: 'Flag a barrier',
  },
  proposalState: 'pending',
};

describe('EddyApprovalCard', () => {
  beforeEach(() => {
    useEddyStore.setState({ messages: [{ ...message }] });
    vi.clearAllMocks();
  });

  it('renders the proposal — tier, title, params and runner-up', () => {
    const { getByText } = render(<EddyApprovalCard message={message} surface="rtdc" />);
    expect(getByText('Imaging delay on 3W')).toBeTruthy();
    expect(getByText(/T1 · low/)).toBeTruthy();
    expect(getByText('3W')).toBeTruthy();
    expect(getByText(/Runner-up:/)).toBeTruthy();
  });

  it('approve posts the proposal (with approve=true) and marks it approved', async () => {
    const { getByText } = render(<EddyApprovalCard message={message} surface="rtdc" />);
    fireEvent.click(getByText('Approve'));

    await waitFor(() =>
      expect(proposeEddyAction).toHaveBeenCalledWith(
        expect.objectContaining({ action_type: 'flag_barrier', approve: true, surface: 'rtdc' }),
      ),
    );
    await waitFor(() => expect(useEddyStore.getState().messages[0].proposalState).toBe('approved'));
  });

  it('dismiss marks denied without calling the api (Eddy never executes)', () => {
    const { getByText } = render(<EddyApprovalCard message={message} surface="rtdc" />);
    fireEvent.click(getByText('Dismiss'));
    expect(proposeEddyAction).not.toHaveBeenCalled();
    expect(useEddyStore.getState().messages[0].proposalState).toBe('denied');
  });

  it('honours the design canon — no raw white/gray surfaces', () => {
    const { container } = render(<EddyApprovalCard message={message} surface="rtdc" />);
    expect(container.innerHTML).not.toMatch(/\bbg-white\b/);
    expect(container.innerHTML).not.toMatch(/\bbg-gray-/);
  });
});
