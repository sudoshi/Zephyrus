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

describe('RTDC Ancillary Services owned drill-through', () => {
  it('links imaging, Laboratory, and Pharmacy tiles to unit-scoped owned workspaces', () => {
    render(<AncillaryServices unitServices={[{
      id: 7,
      name: 'NSICU',
      services: {
        ct_mri: {
          value: 75,
          trend: [],
          drillHref: '/radiology/worklist?unitId=7&source=ancillary_services',
        },
        lab: { value: 40, trend: [], drillHref: '/lab?unitId=7&source=ancillary_services' },
        pharmacy: { value: 55, trend: [], drillHref: '/pharmacy?unitId=7&source=ancillary_services' },
      },
    }]} />);

    fireEvent.click(screen.getByRole('button', { name: 'Matrix' }));

    expect(screen.getByRole('link', {
      name: 'Open CT/MRI Radiology worklist for NSICU',
    })).toHaveAttribute(
      'href',
      '/radiology/worklist?unitId=7&source=ancillary_services',
    );
    expect(screen.getByRole('link', {
      name: 'Open Lab Laboratory Flow Board for NSICU',
    })).toHaveAttribute('href', '/lab?unitId=7&source=ancillary_services');
    expect(screen.getByRole('link', {
      name: 'Open Pharmacy Medication Flow Board for NSICU',
    })).toHaveAttribute('href', '/pharmacy?unitId=7&source=ancillary_services');
  });
});
