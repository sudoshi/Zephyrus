import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EnterpriseImport, type EnterpriseGovernance } from '@/Components/Deployment/EnterpriseImport';

vi.mock('axios', () => ({
  default: {
    post: vi.fn(),
    isAxiosError: vi.fn(() => false),
  },
}));

const governance: EnterpriseGovernance = {
  canManage: true,
  entityTypes: ['organizations', 'markets', 'facilities', 'service_lines', 'locations'],
  pendingChanges: [],
  changeHistory: [
    {
      entityType: 'organization',
      naturalKey: 'IDN_ONE',
      changeKind: 'create',
      sourceOfTruth: 'authoritative_feed',
      changedFields: ['name'],
      recordedAtIso: '2026-07-13T12:00:00Z',
      governedChangeRequestUuid: 'abc',
    },
  ],
};

const preview = {
  summary: { create: 1, update: 1, conflict: 1, no_change: 0, blocked: 0 },
  entities: {
    organizations: {
      total: 2,
      rows: [
        { naturalKey: 'IDN_NEW', displayName: 'New IDN', changeKind: 'create', changedFields: ['name'], conflictKey: 'organizations:IDN_NEW', conflictReason: null, blockedReason: null },
        { naturalKey: 'IDN_OLD', displayName: 'Renamed IDN', changeKind: 'update', changedFields: ['name'], conflictKey: 'organizations:IDN_OLD', conflictReason: null, blockedReason: null },
      ],
    },
  },
  conflicts: [
    { conflictKey: 'organizations:IDN_DUP', entityType: 'organizations', naturalKey: 'IDN_DUP', reason: 'external_identifier_collision', collidingNaturalKey: 'IDN_EXISTING', resolution: null },
  ],
  unresolvedConflictCount: 1,
  readiness: { score: 66, committable: false, appliedCount: 2, blockedCount: 0, unresolvedConflictCount: 1 },
  payloadSha256: 'a'.repeat(64),
};

describe('EnterpriseImport', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('previews a pasted payload and renders the create/update/conflict diff', async () => {
    (axios.post as ReturnType<typeof vi.fn>).mockResolvedValueOnce({ data: preview });

    render(<EnterpriseImport governance={governance} />);

    fireEvent.change(screen.getByLabelText(/Registry payload/i), {
      target: { value: '{"organizations":[{"key":"IDN_NEW","name":"New IDN"}]}' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Preview changes/i }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledWith(
      '/admin/enterprise/import/preview',
      expect.objectContaining({ payload: expect.any(Object) }),
    ));

    // Diff counts render (create/update/conflict) and readiness scoring shows not-committable.
    expect(await screen.findByText('Conflict review')).toBeInTheDocument();
    expect(screen.getByText(/not committable/i)).toBeInTheDocument();
    expect(screen.getByText(/IDN_EXISTING/)).toBeInTheDocument();
  });

  it('rejects invalid JSON before calling the server', async () => {
    render(<EnterpriseImport governance={governance} />);

    fireEvent.change(screen.getByLabelText(/Registry payload/i), { target: { value: 'not json' } });
    fireEvent.click(screen.getByRole('button', { name: /Preview changes/i }));

    expect(await screen.findByText(/not valid JSON/i)).toBeInTheDocument();
    expect(axios.post).not.toHaveBeenCalled();
  });

  it('resolves a conflict by re-previewing with the chosen resolution', async () => {
    (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: preview });
    render(<EnterpriseImport governance={governance} />);

    fireEvent.change(screen.getByLabelText(/Registry payload/i), {
      target: { value: '{"organizations":[]}' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Preview changes/i }));
    await screen.findByText('Conflict review');

    fireEvent.click(screen.getByRole('button', { name: 'Adopt' }));

    await waitFor(() => expect(axios.post).toHaveBeenLastCalledWith(
      '/admin/enterprise/import/preview',
      expect.objectContaining({ conflict_resolutions: { 'organizations:IDN_DUP': 'adopt' } }),
    ));
  });

  it('shows the append-only change history', () => {
    render(<EnterpriseImport governance={governance} />);
    expect(screen.getByText('Change history')).toBeInTheDocument();
    expect(screen.getByText(/IDN_ONE/)).toBeInTheDocument();
  });
});
