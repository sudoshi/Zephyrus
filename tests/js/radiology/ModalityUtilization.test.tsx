import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ModalityUtilizationPage from '@/Pages/Radiology/ModalityUtilization';
import { modalityUtilizationSchema, type ModalityUtilization } from '@/features/radiology/schemas';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, headerContent, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{headerContent}{children}</> }));
vi.mock('@/features/radiology/hooks', () => ({ useModalityUtilization: (data: ModalityUtilization) => ({ data, refetch: vi.fn() }) }));
vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: any) => <div>{children}</div>,
  BarChart: ({ children }: any) => <div>{children}</div>,
  Bar: ({ name }: any) => <span>{name}</span>,
  ReferenceLine: ({ label }: any) => <span>{label?.value}</span>,
  CartesianGrid: () => null, Legend: () => null, Tooltip: () => null, XAxis: () => null, YAxis: () => null,
}));

function payload(overrides: Partial<ModalityUtilization> = {}): ModalityUtilization {
  return modalityUtilizationSchema.parse({
    generatedAt: '2026-07-12T14:00:00+00:00',
    sourceCutoffAt: '2026-07-12T11:01:00+00:00',
    state: 'normal',
    stateMessage: 'All matching scanners have declared staffed hours and complete MPPS interval coverage.',
    filters: { date: '2026-07-12', startTime: '08:00', endTime: '16:00', modality: 'CT' },
    filterOptions: { modalities: [{ code: 'CT', label: 'Computed Tomography' }, { code: 'MRI', label: 'Magnetic Resonance Imaging' }] },
    coverage: { status: 'complete', mppsFeedPresent: true, scannerCount: 1, coveredScannerCount: 1, candidateExamCount: 2, coveredExamCount: 2, percent: 100, warning: null },
    summary: { scannerCount: 1, availableMinutes: 480, examMinutes: 120, plannedDowntimeMinutes: 30, unplannedDowntimeMinutes: 60, idleMinutes: 270, utilizationPercent: 25, dataCoveragePercent: 100, patientMix: { ed: 1, inpatient: 1, outpatient: 0, other: 0, total: 2 }, reconciliationDeltaMinutes: 0 },
    definitions: {
      available: 'Declared staffed operating minutes.', exam: 'MPPS-backed performed intervals.', downtime: 'Clipped downtime union.',
      idle: 'Covered remainder.', utilization: 'Exam minutes divided by staffed minutes.', referenceLine: 'Covered portfolio average.',
    },
    referenceLines: [{ key: 'portfolio_average', label: 'Portfolio average', value: 25, definition: 'Derived covered average.' }],
    scanners: [{
      scannerUuid: '11111111-1111-4111-8111-111111111111', label: 'CT 1', modality: 'CT', capacity: 1, timezone: 'UTC',
      availableWindows: [{ startAt: '2026-07-12T08:00:00+00:00', endAt: '2026-07-12T16:00:00+00:00' }],
      availableMinutes: 480, examMinutes: 120, plannedDowntimeMinutes: 30, unplannedDowntimeMinutes: 60, idleMinutes: 270, utilizationPercent: 25, reconciliationDeltaMinutes: 0,
      coverage: { status: 'complete', percent: 100, candidateExamCount: 2, coveredExamCount: 2, warning: null },
      patientMix: { ed: 1, inpatient: 1, outpatient: 0, other: 0, total: 2 },
      segments: [
        { startAt: '2026-07-12T08:00:00+00:00', endAt: '2026-07-12T09:00:00+00:00', type: 'idle', minutes: 60, label: 'Idle' },
        { startAt: '2026-07-12T09:00:00+00:00', endAt: '2026-07-12T11:00:00+00:00', type: 'exam', minutes: 120, label: 'Covered exam activity' },
        { startAt: '2026-07-12T11:00:00+00:00', endAt: '2026-07-12T11:30:00+00:00', type: 'planned_downtime', minutes: 30, label: 'Planned downtime' },
        { startAt: '2026-07-12T11:30:00+00:00', endAt: '2026-07-12T12:30:00+00:00', type: 'unplanned_downtime', minutes: 60, label: 'Unplanned downtime' },
        { startAt: '2026-07-12T12:30:00+00:00', endAt: '2026-07-12T16:00:00+00:00', type: 'idle', minutes: 210, label: 'Idle' },
      ],
    }],
    ...overrides,
  });
}

describe('Radiology Modality Utilization', () => {
  it('renders filters, covered calculations, reference line, downtime overlay, definitions, and accessible chart summary', () => {
    render(<ModalityUtilizationPage modalityUtilization={payload()} />);

    expect(screen.getByRole('heading', { name: 'Modality Utilization' })).toBeInTheDocument();
    expect(screen.getByLabelText('Date')).toHaveValue('2026-07-12');
    expect(screen.getByLabelText('Start time')).toHaveValue('08:00');
    expect(screen.getByLabelText('End time')).toHaveValue('16:00');
    expect(screen.getByLabelText('Modality')).toHaveValue('CT');
    expect(screen.getByText('25.0%')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: /stacked scanner operating-window utilization/i })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Accessible scanner utilization summary' })).toBeInTheDocument();
    expect(screen.getAllByText('Portfolio average').length).toBeGreaterThan(0);
    expect(screen.getByLabelText('Planned downtime, 30 minutes')).toBeInTheDocument();
    expect(screen.getByLabelText('Unplanned downtime, 60 minutes')).toBeInTheDocument();
    expect(screen.getByText('ED 1 · IP 1 · OP 0')).toBeInTheDocument();
    expect(screen.getByLabelText(/Definition: Exam minutes divided by staffed minutes/)).toBeInTheDocument();
    expect(screen.queryByText(/no-show/i)).not.toBeInTheDocument();
  });

  it('renders missing MPPS coverage as unavailable and unknown instead of idle or zero utilization', () => {
    const degraded = payload({
      state: 'degraded',
      stateMessage: 'Utilization is withheld where staffed hours or authoritative MPPS interval coverage is incomplete.',
      coverage: { status: 'missing', mppsFeedPresent: false, scannerCount: 1, coveredScannerCount: 0, candidateExamCount: 1, coveredExamCount: 0, percent: 0, warning: 'No governed MPPS feed is registered.' },
      summary: { scannerCount: 1, availableMinutes: 480, examMinutes: null, plannedDowntimeMinutes: 0, unplannedDowntimeMinutes: 0, idleMinutes: null, utilizationPercent: null, dataCoveragePercent: 0, patientMix: { ed: 0, inpatient: 0, outpatient: 1, other: 0, total: 1 }, reconciliationDeltaMinutes: null },
      referenceLines: [],
      scanners: [{ ...payload().scanners[0], examMinutes: null, idleMinutes: null, utilizationPercent: null, reconciliationDeltaMinutes: null, coverage: { status: 'missing_feed', percent: 0, candidateExamCount: 1, coveredExamCount: 0, warning: 'No governed MPPS feed is registered; machine utilization is unavailable.' }, segments: [{ startAt: '2026-07-12T08:00:00+00:00', endAt: '2026-07-12T16:00:00+00:00', type: 'unknown', minutes: 480, label: 'Unknown activity coverage' }] }],
    });
    render(<ModalityUtilizationPage modalityUtilization={degraded} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Coverage warning');
    expect(screen.getAllByText('Unavailable').length).toBeGreaterThanOrEqual(2);
    expect(screen.getByLabelText('Unknown activity coverage, 480 minutes')).toBeInTheDocument();
    expect(screen.queryByText('0.0%')).not.toBeInTheDocument();
  });
});
