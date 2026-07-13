import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ClinicalPayloadGovernance from '@/Components/Admin/ClinicalPayloadGovernance';

vi.mock('axios', () => ({
  default: {
    post: vi.fn(),
    isAxiosError: vi.fn(),
  },
}));

const object = {
  id: 88,
  uuid: '019f0000-0000-7000-8000-000000000088',
  kind: 'raw_message',
  classification: 'restricted_phi',
  status: 'ready',
  legalHold: false,
  retentionPolicy: 'clinical-default',
  retainUntil: '2033-07-13T12:00:00Z',
  createdAt: '2026-07-13T12:00:00Z',
  lastVerifiedAt: '2026-07-13T12:10:00Z',
  deletionBlockers: [],
};

const baseProps = {
  sourceId: 3,
  actionable: true,
  objects: [object],
  quarantines: [],
  changes: [],
  currentUserId: 22,
  canManage: true,
  canOperate: true,
  canApprove: true,
};

describe('clinical payload governance controls', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(axios.isAxiosError).mockReturnValue(false);
    vi.mocked(axios.post).mockResolvedValue({ data: { data: {} } });
  });

  it('requests an exact-object legal hold with only bounded governance fields', async () => {
    render(<ClinicalPayloadGovernance {...baseProps} />);

    fireEvent.click(screen.getByRole('button', { name: /apply hold/i }));
    fireEvent.change(screen.getByLabelText('Hold reason code'), { target: { value: 'legal_case_0713' } });
    fireEvent.change(screen.getByLabelText('Governed rationale'), {
      target: { value: 'Preserve this exact opaque object under documented legal authorization.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Request independent approval' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledWith(
      '/api/admin/integrations/sources/3/payload-objects/88/hold-requests',
      {
        operation: 'apply',
        hold_reason_code: 'legal_case_0713',
        reason: 'Preserve this exact opaque object under documented legal authorization.',
      },
    ));
    expect(document.body).not.toHaveTextContent('RECOGNIZABLE-PATIENT');
    expect(document.body).not.toHaveTextContent('object_key');
    expect(document.body).not.toHaveTextContent('ciphertext_sha256');
  });

  it('supports independent decision and exact approved execution without content fields', async () => {
    const pending = {
      uuid: '019f0000-0000-7000-8000-000000000099',
      action: 'purge_clinical_payload',
      subjectType: 'clinical_payload',
      subjectId: object.uuid,
      status: 'pending_approval',
      operation: 'purge',
      objectId: 88,
      quarantineId: null,
      authorUserId: 11,
      decidedByUserId: null,
      requestedAt: '2026-07-13T12:00:00Z',
      expiresAt: '2026-07-20T12:00:00Z',
      decidedAt: null,
      executedAt: null,
    };
    const approved = { ...pending, uuid: '019f0000-0000-7000-8000-000000000100', status: 'approved', decidedByUserId: 22, decidedAt: '2026-07-13T12:10:00Z' };
    render(<ClinicalPayloadGovernance {...baseProps} changes={[pending, approved]} />);

    fireEvent.change(screen.getByLabelText('Independent rationale'), {
      target: { value: 'Dependency, retention, and exact-object evidence supports this decision.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(axios.post).toHaveBeenCalledWith(
      `/api/admin/integrations/governed-changes/${pending.uuid}/decision`,
      {
        decision: 'approved',
        reason: 'Dependency, retention, and exact-object evidence supports this decision.',
      },
    ));

    fireEvent.click(screen.getByRole('button', { name: 'Execute approved change' }));
    await waitFor(() => expect(axios.post).toHaveBeenCalledWith(
      `/api/admin/integrations/governed-changes/${approved.uuid}/sources/3/payload-objects/88/execute-purge`,
    ));
  });

  it('keeps aggregate scopes read-only and hides opaque records', () => {
    render(<ClinicalPayloadGovernance {...baseProps} sourceId={null} actionable={false} />);
    expect(screen.getByText(/Select one exact integration source/i)).toBeInTheDocument();
    expect(screen.queryByText(/#88/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /purge|hold|release/i })).not.toBeInTheDocument();
  });
});
