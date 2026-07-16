import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { GovernedDecisionControls, GovernedExecutionControl } from '@/Pages/Integrations/Index';

vi.mock('axios', () => ({
  default: {
    post: vi.fn(),
    isAxiosError: vi.fn(),
  },
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
  const original = await importOriginal<typeof import('@inertiajs/react')>();
  return { ...original, router: { visit: vi.fn() } };
});

const change = {
  changeRequestUuid: '019f0000-0000-7000-8000-000000000001',
  actionType: 'activate_production_source',
  subjectType: 'integration_source',
  subjectId: '7',
  authorUserId: 11,
  organizationId: null,
  facilityId: 5,
  sourceId: 7,
  status: 'pending',
  requestedAtIso: '2026-07-13T12:00:00Z',
  expiresAtIso: '2026-07-20T12:00:00Z',
  decidedByUserId: null,
  decidedAtIso: null,
  executedAtIso: null,
};

describe('Governed integration decision controls', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.isAxiosError).mockReset();
  });

  it('submits only an independent decision and bounded rationale', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { data: { decision: 'rejected' } } });
    const onRefresh = vi.fn().mockResolvedValue(undefined);
    render(<GovernedDecisionControls change={change} currentUserId={22} scopeMatches onRefresh={onRefresh} />);

    const button = screen.getByRole('button', { name: 'Record decision' });
    expect(button).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Decision'), { target: { value: 'rejected' } });
    fireEvent.change(screen.getByLabelText('Independent rationale'), { target: { value: 'Production evidence is incomplete.' } });
    fireEvent.click(button);

    await waitFor(() => expect(axios.post).toHaveBeenCalledWith(
      `/api/admin/integrations/governed-changes/${change.changeRequestUuid}/decision`,
      { decision: 'rejected', reason: 'Production evidence is incomplete.' },
    ));
    expect(onRefresh).toHaveBeenCalledOnce();
  });

  it('does not offer self-approval to the request author', () => {
    render(<GovernedDecisionControls change={change} currentUserId={11} scopeMatches onRefresh={vi.fn()} />);
    expect(screen.getByText(/author cannot decide/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Record decision' })).not.toBeInTheDocument();
  });

  it('re-enters and executes only the exact approved credential rotation target', async () => {
    vi.mocked(axios.post).mockResolvedValue({
      data: {
        data: {
          credentialId: 9,
          sourceId: 3,
          credentialKey: 'smart-backend',
          credentialType: 'oauth2_private_key',
          status: 'rotating',
          credentialState: 'rotating',
          currentCredentialVersionId: 42,
          secretReferenceConfigured: true,
          certificateReferenceConfigured: false,
          jwksConfigured: false,
          validFromIso: '2026-07-13T13:00:00Z',
          expiresAtIso: '2026-10-13T13:00:00Z',
          rotatesAtIso: '2026-10-01T13:00:00Z',
          rotationOverlapEndsAtIso: '2026-07-14T13:00:00Z',
          revokedAtIso: null,
          lastUsedAtIso: null,
          owner: 'Security',
        },
      },
    });
    const onRefresh = vi.fn().mockResolvedValue(undefined);
    const rotation = {
      ...change,
      actionType: 'rotate_integration_credential',
      subjectType: 'integration_credential',
      subjectId: '3:9',
      sourceId: 3,
      status: 'approved',
      decidedByUserId: 22,
      decidedAtIso: '2026-07-13T12:30:00Z',
    };

    render(<GovernedExecutionControl change={rotation} scopeMatches onRefresh={onRefresh} />);
    const execute = screen.getByRole('button', { name: 'Execute exact approved rotation' });
    expect(execute).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Approved secret reference'), {
      target: { value: 'vault://clinical/data/epic#private_key' },
    });
    fireEvent.change(screen.getByLabelText('Valid from'), {
      target: { value: '2026-07-13T09:00' },
    });
    fireEvent.click(execute);

    await waitFor(() => expect(axios.post).toHaveBeenCalledWith(
      `/api/admin/integrations/governed-changes/${change.changeRequestUuid}/sources/3/credentials/9/execute-rotation`,
      {
        secret_ref: 'vault://clinical/data/epic#private_key',
        valid_from: '2026-07-13T09:00',
      },
    ));
    expect(onRefresh).toHaveBeenCalledOnce();
  });
});
