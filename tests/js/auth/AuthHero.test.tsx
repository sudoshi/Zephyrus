import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AuthHero } from '@/Components/Auth/AuthHero';

describe('AuthHero', () => {
  it('renders the wordmark, tagline, and a known feature', () => {
    render(<AuthHero />);
    expect(screen.getByText('Zephyrus')).toBeInTheDocument();
    expect(screen.getByText(/Healthcare Operations Platform/i)).toBeInTheDocument();
    expect(screen.getByText(/Real-Time Demand & Capacity/i)).toBeInTheDocument();
  });

  it('renders the three pill section labels', () => {
    render(<AuthHero />);
    expect(screen.getByText('Modules')).toBeInTheDocument();
    expect(screen.getByText('Capabilities')).toBeInTheDocument();
    expect(screen.getByText(/Standards & Security/i)).toBeInTheDocument();
  });
});
