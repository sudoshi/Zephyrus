import React, { type ReactNode } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AncillaryServices from '@/Pages/RTDC/AncillaryServices';

vi.mock('@inertiajs/react', () => ({
  router: { visit: vi.fn() },
}));

vi.mock('@/Components/RTDC/RTDCPageLayout', () => ({
  default: ({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) => (
    <main><h1>{title}</h1><p>{subtitle}</p>{children}</main>
  ),
}));
vi.mock('@/Components/system', () => ({
  Section: ({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) => (
    <section><h2>{title}</h2>{actions}{children}</section>
  ),
  MetricGrid: () => null,
  Panel: ({ children, ...props }: { children: ReactNode } & React.HTMLAttributes<HTMLDivElement>) => (
    <div {...props}>{children}</div>
  ),
  metric: (value: unknown) => value,
}));
vi.mock('@/Components/Analytics/Common/TrendChart', () => ({ default: () => null }));
vi.mock('@/Components/RTDC/TrendsModal', () => ({ default: () => null }));
vi.mock('@iconify/react', () => ({ Icon: () => <span aria-hidden="true" /> }));

describe('RTDC Ancillary Services Radiology drill-through', () => {
  it('links imaging tiles to the unit-scoped Radiology worklist without linking other services', () => {
    render(<AncillaryServices unitServices={[{
      id: 7,
      name: 'NSICU',
      services: {
        ct_mri: {
          value: 75,
          trend: [],
          drillHref: '/radiology/worklist?unitId=7&source=ancillary_services',
        },
        lab: { value: 40, trend: [], drillHref: null },
      },
    }]} />);

    fireEvent.click(screen.getByRole('button', { name: 'Matrix' }));

    expect(screen.getByRole('link', {
      name: 'Open CT/MRI Radiology worklist for NSICU',
    })).toHaveAttribute(
      'href',
      '/radiology/worklist?unitId=7&source=ancillary_services',
    );
    expect(screen.queryByRole('link', {
      name: 'Open Lab Radiology worklist for NSICU',
    })).not.toBeInTheDocument();
  });
});
