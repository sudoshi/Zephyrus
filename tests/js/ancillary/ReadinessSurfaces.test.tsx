import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import DischargePriorities from '@/Pages/RTDC/DischargePriorities';
import Treatment from '@/Pages/ED/Operations/Treatment';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: any) => <a href={href} {...props}>{children}</a>,
  router: { visit },
}));
vi.mock('@iconify/react', () => ({ Icon: ({ icon, ...props }: any) => <svg aria-label={icon} {...props} /> }));
vi.mock('@/Components/RTDC/RTDCPageLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Dashboard/DashboardLayout', () => ({ default: ({ children }: any) => <main>{children}</main> }));
vi.mock('@/Components/Common/PageContentLayout', () => ({ default: ({ title, subtitle, children }: any) => <><h1>{title}</h1><p>{subtitle}</p>{children}</> }));
vi.mock('@/Components/Dashboard/Charts/BarChart', () => ({ default: () => <div>Chart</div> }));
vi.mock('@/Components/system', () => ({
  metric: (value: any) => value,
  MetricGrid: () => <div>Metrics</div>,
  Section: ({ title, children }: any) => <section><h2>{title}</h2>{children}</section>,
  Panel: ({ children }: any) => <div>{children}</div>,
  EmptyState: ({ message }: any) => <div>{message}</div>,
}));

const freshness = {
  status: 'fresh' as const,
  asOf: '2026-07-12T14:00:00+00:00',
  sourceCutoffAt: '2026-07-12T13:58:00+00:00',
  lagMinutes: 2,
  sourceLabel: 'Radiology operational feeds',
  explanation: null,
};

const imaging = {
  key: 'imaging', label: 'Imaging', status: 'blocked' as const, state: 'blocked' as const,
  pendingCount: 1, oldestAgeMinutes: 47, blocking: true, freshness,
  drillTarget: '/radiology/worklist?search=11111111-1111-4111-8111-111111111111&source=rtdc',
  topOrderUuid: '11111111-1111-4111-8111-111111111111',
  drillHref: '/radiology/worklist?search=11111111-1111-4111-8111-111111111111&source=rtdc',
};

describe('imaging readiness surfaces', () => {
  it('renders an accessible RTDC readiness vector and visits its bounded drill', () => {
    render(<DischargePriorities
      priority1={[{
        id: 42, name: 'Demo Patient', age: 58, hospital: 'Summit Regional', unit: '5 East', service: 'Internal Medicine',
        los: 5, expectedLos: 4, unitCapacity: '92%', improvement: 'Rapid', risk: 'Low', priority: 1, imaging,
      }]}
      priority2={[]} priority3={[]} priority4={[]}
      hospitals={['Summit Regional']} services={['Internal Medicine']} units={['5 East']}
    />);

    const drill = screen.getByRole('button', { name: 'Open Imaging: Blocked' });
    expect(drill).toHaveTextContent('1 pending · 47 min oldest');
    fireEvent.click(drill);
    expect(visit).toHaveBeenCalledWith(imaging.drillHref);
  });

  it('renders the ED count and oldest age as an authorized filtered link', () => {
    render(<Treatment board={[{
      id: 'V0042', edVisitId: 42, patientRef: 'demo', room: 'Acute 2', chiefComplaint: 'Abdominal Pain', esiLevel: 3,
      treatmentMinutes: 61, losMinutes: 120, dispositionMinutes: null, status: 'In Treatment', statusTone: 'info',
      provider: 'Dr. Demo', nurse: 'Nurse Demo', pendingOrders: ['CBC'],
      imaging: { ...imaging, drillTarget: imaging.drillTarget.replace('rtdc', 'ed'), drillHref: imaging.drillHref.replace('rtdc', 'ed') },
    }]} acuityMix={[]} />);

    const chip = screen.getByRole('link', { name: /Open Imaging: Blocked/ });
    expect(chip).toHaveTextContent('1 imaging · 47 min oldest');
    expect(chip).toHaveAttribute('href', expect.stringContaining('source=ed'));
  });

  it('announces stale imaging as unknown instead of ready', () => {
    render(<Treatment board={[{
      id: 'V0043', edVisitId: 43, patientRef: 'demo-stale', room: 'Acute 3', chiefComplaint: 'Headache', esiLevel: 3,
      treatmentMinutes: 40, losMinutes: 80, dispositionMinutes: null, status: 'In Treatment', statusTone: 'info',
      provider: 'Dr. Demo', nurse: 'Nurse Demo', pendingOrders: [],
      imaging: { ...imaging, status: 'ready', state: 'ready', pendingCount: 0, oldestAgeMinutes: null, blocking: false, freshness: { ...freshness, status: 'stale' } },
    }]} acuityMix={[]} />);

    expect(screen.getByRole('link', { name: /Open Imaging: Unknown/ })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Open Imaging: Ready/ })).not.toBeInTheDocument();
  });
});
