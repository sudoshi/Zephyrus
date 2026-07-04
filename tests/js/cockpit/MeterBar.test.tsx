// tests/js/cockpit/MeterBar.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MeterBar } from '@/Components/cockpit/MeterBar';

describe('MeterBar', () => {
  it('clamps pct to 0–100 and fills with the status color', () => {
    render(<MeterBar pct={140} status="critical" label="Occupancy" />);
    const meter = screen.getByRole('meter', { name: 'Occupancy' });
    expect(meter).toHaveAttribute('aria-valuenow', '100');
    const fill = meter.firstElementChild as HTMLElement;
    expect(fill.style.width).toBe('100%');
    expect(fill.style.background).toBe('var(--critical)');
  });

  it('is decorative (aria-hidden) when no label is given', () => {
    const { container } = render(<MeterBar pct={40} status="success" />);
    expect(container.firstElementChild).toHaveAttribute('aria-hidden', 'true');
  });

  it('exposes the legacy fill testid hook', () => {
    render(<MeterBar pct={55} status="warning" testId="kr-progress-x" />);
    expect(screen.getByTestId('kr-progress-x')).toBeInTheDocument();
  });
});
