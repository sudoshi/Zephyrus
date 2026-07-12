import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { NAV_SECTIONS } from '@/config/navigationConfig';
import { SectionMenuPanel } from '@/Components/Navigation/SectionMenuPanel';

const workspaces = NAV_SECTIONS.find((section) => section.key === 'workspaces')!;
const study = NAV_SECTIONS.find((section) => section.key === 'study')!;

describe('SectionMenuPanel', () => {
  it('projects workspace domains behind one section-level control', () => {
    render(
      <SectionMenuPanel
        section={workspaces}
        access={{ isAdmin: false }}
        url="/rtdc/patient-flow-navigator"
        onNavigate={vi.fn()}
      />,
    );

    expect(screen.getByRole('tab', { name: 'RTDC' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('link', { name: 'Patient Flow 4D' })).toHaveAttribute(
      'href',
      '/rtdc/patient-flow-navigator',
    );

    fireEvent.click(screen.getByRole('tab', { name: 'Transport' }));
    expect(screen.getByRole('link', { name: 'Dispatch' })).toHaveAttribute(
      'href',
      '/transport/dispatch',
    );
    expect(screen.queryByRole('link', { name: 'Integrations' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: 'Radiology' }));
    expect(screen.getByRole('link', { name: 'Imaging Flow Board' })).toHaveAttribute(
      'href',
      '/radiology',
    );
    expect(screen.getByRole('link', { name: 'Reads & Results' })).toHaveAttribute(
      'href',
      '/radiology/reads',
    );
    expect(document.querySelectorAll('a[href="/radiology"]')).toHaveLength(1);
  });

  it('supports arrow-key navigation across workspace tabs', () => {
    render(
      <SectionMenuPanel
        section={workspaces}
        access={{ isAdmin: false }}
        url="/rtdc/bed-tracking"
        onNavigate={vi.fn()}
      />,
    );

    fireEvent.keyDown(screen.getByRole('tablist', { name: 'Workspaces navigation' }), {
      key: 'ArrowRight',
    });

    expect(screen.getByRole('tab', { name: 'Emergency' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('link', { name: 'Triage' })).toHaveAttribute(
      'href',
      '/ed/operations/triage',
    );
  });

  it('shows staffing administration only with its server capability', () => {
    const { rerender } = render(
      <SectionMenuPanel
        section={workspaces}
        access={{ isAdmin: false }}
        url="/staffing"
        onNavigate={vi.fn()}
      />,
    );
    expect(screen.queryByRole('link', { name: 'Staffing Alignment' })).not.toBeInTheDocument();

    rerender(
      <SectionMenuPanel
        section={workspaces}
        access={{ isAdmin: false, can: { manage_staffing_alignment: true } }}
        url="/staffing"
        onNavigate={vi.fn()}
      />,
    );
    expect(screen.getByRole('link', { name: 'Staffing Alignment' })).toHaveAttribute(
      'href',
      '/staffing/administration',
    );
  });

  it('renders the Study menu in task order without duplicate dashboard destinations', () => {
    render(
      <SectionMenuPanel
        section={study}
        access={{ isAdmin: false }}
        url="/analytics/arena"
        onNavigate={vi.fn()}
      />,
    );

    expect(
      screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent),
    ).toEqual([
      'Overview',
      'Process Analysis',
      'Planning',
      'Perioperative Performance',
      'Ancillary Performance',
      'Capacity Trends',
      'ED & Transport Trends',
    ]);
    expect(document.querySelectorAll('a[href="/analytics"]')).toHaveLength(1);
    expect(screen.queryByRole('link', { name: 'Operations Intelligence' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: 'Improvement' }));

    expect(
      screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent),
    ).toEqual(['Diagnose', 'Run & Learn']);
    expect(document.querySelectorAll('a[href="/improvement/active"]')).toHaveLength(1);
    expect(screen.queryByRole('link', { name: 'Active Cycles', exact: true })).toBeInTheDocument();
  });
});
