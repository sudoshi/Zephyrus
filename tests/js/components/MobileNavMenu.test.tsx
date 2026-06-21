import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { MobileNavMenu } from '@/Components/Navigation/MobileNavMenu';

describe('MobileNavMenu', () => {
  it('is closed initially — panel domains not shown', () => {
    render(<MobileNavMenu isAdmin={false} url="" />);
    expect(screen.queryByRole('link', { name: /RTDC Dashboard/i })).not.toBeInTheDocument();
  });

  it('opens on hamburger click and lists domains', () => {
    render(<MobileNavMenu isAdmin={false} url="" />);
    fireEvent.click(screen.getByRole('button', { name: /open menu/i }));
    expect(screen.getByText('RTDC')).toBeInTheDocument();
    expect(screen.getByText('Perioperative')).toBeInTheDocument();
    expect(screen.getByText('Improvement')).toBeInTheDocument();
  });

  it('hides Admin for non-admins and shows it for admins', () => {
    const { rerender } = render(<MobileNavMenu isAdmin={false} url="" />);
    fireEvent.click(screen.getByRole('button', { name: /open menu/i }));
    expect(screen.queryByText('Admin')).not.toBeInTheDocument();

    rerender(<MobileNavMenu isAdmin url="" />);
    fireEvent.click(screen.getByRole('button', { name: /open menu/i }));
    expect(screen.getByText('Admin')).toBeInTheDocument();
  });
});
