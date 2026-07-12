import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { visibleSections } from '@/config/navigationConfig';
import { MobileNavDrawer } from '@/Components/Navigation/MobileNavDrawer';

describe('MobileNavDrawer', () => {
  it('exposes the complete workspace tree through nested disclosures', async () => {
    const access = { isAdmin: false } as const;
    render(
      <MobileNavDrawer sections={visibleSections(access)} access={access} url="/dashboard" />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Open main navigation' }));
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Workspaces' })).toHaveAttribute('data-open');

    fireEvent.click(screen.getByRole('button', { name: 'RTDC' }));
    expect(screen.getByRole('link', { name: 'Patient Flow 4D' })).toHaveAttribute(
      'href',
      '/rtdc/patient-flow-navigator',
    );
    expect(screen.queryByRole('button', { name: 'Patient Flow' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Radiology' }));
    expect(screen.getByRole('link', { name: 'Imaging Flow Board' })).toHaveAttribute(
      'href',
      '/radiology',
    );
    expect(screen.getByRole('link', { name: 'Order Worklist' })).toHaveAttribute(
      'href',
      '/radiology/worklist',
    );
    expect(screen.getByRole('link', { name: 'Modality Utilization' })).toHaveAttribute(
      'href',
      '/radiology/modality',
    );
    expect(screen.getByRole('link', { name: 'Reads & Results' })).toHaveAttribute(
      'href',
      '/radiology/reads',
    );
  });

  it('closes with Escape and returns focus to the trigger', async () => {
    const access = { isAdmin: false } as const;
    render(
      <MobileNavDrawer sections={visibleSections(access)} access={access} url="/dashboard" />,
    );

    const trigger = screen.getByRole('button', { name: 'Open main navigation' });
    fireEvent.click(trigger);
    expect(await screen.findByRole('dialog')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(trigger).toHaveFocus();
  });

  it('shows Integrations only when the capability is present', async () => {
    const access = { isAdmin: false, can: { view_integrations: true } } as const;
    render(
      <MobileNavDrawer sections={visibleSections(access)} access={access} url="/dashboard" />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Open main navigation' }));
    expect(await screen.findByRole('link', { name: 'Integrations' })).toHaveAttribute(
      'href',
      '/integrations',
    );
  });
});
