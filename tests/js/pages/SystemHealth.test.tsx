import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SystemHealth from '@/Pages/Admin/SystemHealth';

vi.mock('axios', () => ({
  default: {
    post: vi.fn(),
    isAxiosError: vi.fn(),
  },
}));

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

const observation = {
  key: 'database',
  label: 'Primary database',
  category: 'Data plane',
  status: 'healthy' as const,
  recordedStatus: 'healthy' as const,
  summary: 'The primary database accepted a bounded read.',
  errorCode: null,
  required: true,
  owner: 'Platform Engineering',
  runbookRef: 'admin-system-health#database',
  runbookUrl: null,
  observedAt: '2026-07-13T12:00:00Z',
  freshUntil: '2026-07-13T12:03:00Z',
  durationMs: 3,
  origin: 'scheduled' as const,
  stale: false,
  details: { driver: 'pgsql', readSucceeded: true },
  href: '/admin/system-health/database',
};

const snapshot = {
  generatedAt: '2026-07-13T12:00:30Z',
  batchUuid: null,
  correlationId: null,
  batchObservationCount: null,
  overallStatus: 'degraded' as const,
  counts: { healthy: 1, warning: 0, critical: 0, unknown: 1, disabled: 0, requiredAttention: 1 },
  lastScheduledAt: '2026-07-13T12:00:00Z',
  observations: [observation, { ...observation, key: 'backups', label: 'Backup evidence', status: 'unknown' as const, recordedStatus: null, observedAt: null, freshUntil: null, durationMs: null, origin: null, errorCode: 'observation_missing', summary: 'No health observation has been recorded.', href: '/admin/system-health/backups' }],
  selectedComponent: null,
  contract: { freshForSeconds: 180, statuses: ['healthy', 'warning', 'critical', 'unknown', 'disabled'] as const, appendOnly: true, externalCallsAllowed: false },
};

describe('System Health administration', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.isAxiosError).mockReset();
  });

  it('renders truthful freshness, ownership, and bounded-diagnostic language', () => {
    render(<SystemHealth snapshot={snapshot} canRunDiagnostics />);

    expect(screen.getByRole('heading', { level: 1, name: 'System Health' })).toBeInTheDocument();
    expect(screen.getByText(/unknown is never presented as healthy/i)).toBeInTheDocument();
    expect(screen.getByText('Primary database')).toBeInTheDocument();
    expect(screen.getByText('Backup evidence')).toBeInTheDocument();
    expect(screen.getByText(/do not call an EHR/i)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View Primary database' })).toHaveAttribute('href', '/admin/system-health/database');
  });

  it('runs diagnostics and refreshes only from the sanitized server contract', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { ...snapshot, batchUuid: '019f-batch', batchObservationCount: 13, overallStatus: 'healthy', counts: { ...snapshot.counts, healthy: 2, unknown: 0, requiredAttention: 0 } } });
    render(<SystemHealth snapshot={snapshot} canRunDiagnostics />);

    fireEvent.click(screen.getByRole('button', { name: 'Run bounded diagnostics' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/admin/system-health/diagnostics'));
    expect((await screen.findAllByText('healthy')).length).toBeGreaterThan(0);
    expect(screen.getByText('Required attention').nextElementSibling).toHaveTextContent('0');
  });

  it('does not render a diagnostic action without capability', () => {
    render(<SystemHealth snapshot={snapshot} canRunDiagnostics={false} />);
    expect(screen.queryByRole('button', { name: /diagnostics/i })).not.toBeInTheDocument();
    expect(screen.getByText(/runDiagnostics capability required/)).toBeInTheDocument();
  });
});
