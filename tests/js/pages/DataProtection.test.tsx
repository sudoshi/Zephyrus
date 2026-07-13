import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import DataProtection from '@/Pages/Admin/DataProtection';

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

vi.mock('@/Components/Admin/AdminScopeSelector', () => ({
  default: () => <div>Scope selector</div>,
}));

const snapshot = {
  generatedAt: '2026-07-13T12:00:00Z',
  scope: { mode: 'source', organizationId: 1, facilityId: 2, sourceId: 3, label: 'Epic Production' },
  provider: { status: 'ready' as const, errorCode: null, disk: 'clinical-payloads', driver: 's3', cipher: 'xchacha20-poly1305-ietf', compression: 'gzip', keyProviderScheme: 'vault', keyProviderVersion: 'version-17', keyReferenceConfigured: true, providerReachable: true },
  coverage: {
    protected: 21,
    legacy: 2,
    eligible: 23,
    coveragePercent: 91,
    targets: [
      { label: 'Raw inbound bodies', protected: 8, legacy: 2, eligible: 10, coveragePercent: 80 },
      { label: 'Normalized inbound bodies', protected: 5, legacy: 0, eligible: 5, coveragePercent: 100 },
      { label: 'FHIR resource versions', protected: 4, legacy: 0, eligible: 4, coveragePercent: 100 },
      { label: 'Canonical replay events', protected: 4, legacy: 0, eligible: 4, coveragePercent: 100 },
    ],
  },
  objects: { total: 21, ready: 20, integrityFailed: 0, legalHolds: 1, items: [{ id: 88, uuid: '019f-object-safe', kind: 'raw_message', classification: 'restricted_phi', status: 'ready', legalHold: false, retentionPolicy: 'clinical-default', retainUntil: '2033-07-13T12:00:00Z', createdAt: '2026-07-13T10:00:00Z', lastVerifiedAt: '2026-07-13T11:30:00Z', deletionBlockers: [] }] },
  backfill: { pending: 1, failed: 0, mismatched: 0, latestRuns: [{ runId: 7, runUuid: '019f-run', sourceId: 3, mode: 'backfill', status: 'completed_with_errors', scanned: 3, protected: 2, skipped: 0, failed: 0, mismatched: 1, errorCode: null, startedAt: '2026-07-13T11:00:00Z', completedAt: '2026-07-13T11:01:00Z' }] },
  quarantine: { open: 1, oldestOpenedAt: '2026-07-13T10:00:00Z', byCategory: { unsafe_content: 1 }, items: [{ id: 9, uuid: '019f-quarantine-safe', objectId: 89, objectUuid: '019f-object-quarantined', objectStatus: 'quarantined', legalHold: false, category: 'unsafe_content', reasonCode: 'scanner_rejected', detectedBy: 'bounded-scanner', openedAt: '2026-07-13T10:00:00Z', deletionBlockers: [] }] },
  retention: { backlog: 2, pendingDeletion: 1, deletionFailures: 0, deletedTombstones: 9 },
  integrity: { lastVerifiedAt: '2026-07-13T11:30:00Z', verifiedLast24Hours: 5, failures: 0 },
  partitioning: { status: 'blocked' as const, partitionedCount: 1, requiredCount: 5, partitionedTables: ['audit.user_events'], remediation: 'Approve the online partition migration.' },
  governance: { actionable: true, changes: [] },
  links: { sourceGovernance: '/integrations?tab=sources', governedChanges: '/integrations?tab=audit&governance=pending', systemHealth: '/admin/system-health' },
};

describe('Data Protection administration', () => {
  it('renders minimum-necessary authority, coverage, quarantine, and lifecycle evidence', () => {
    render(<DataProtection snapshot={snapshot} />);

    expect(screen.getByRole('heading', { level: 1, name: 'Data Protection' })).toBeInTheDocument();
    expect(screen.getByText(/Minimum-necessary boundary: Epic Production/i)).toBeInTheDocument();
    expect(screen.getByText(/never decrypted bodies/i)).toBeInTheDocument();
    expect(screen.getByText('xchacha20-poly1305-ietf')).toBeInTheDocument();
    expect(screen.getByText('Raw inbound bodies')).toBeInTheDocument();
    expect(screen.getByText('Review quarantined payloads')).toBeInTheDocument();
    expect(screen.getByText('Approve online partition migration')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Governed payload lifecycle and recovery' })).toBeInTheDocument();
    expect(screen.getByText(/Clinical content inspection remains prohibited/i)).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent('RECOGNIZABLE-PATIENT');
  });
});
