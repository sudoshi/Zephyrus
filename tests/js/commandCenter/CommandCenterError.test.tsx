// tests/js/commandCenter/CommandCenterError.test.tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CommandCenterError } from '@/Components/CommandCenter/states';

describe('CommandCenterError', () => {
  it('shows a calm, jargon-free message and never the raw technical detail', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const detail = 'Expected array, received string (at unitCensus)';
    render(<CommandCenterError detail={detail} onRetry={() => {}} />);

    expect(screen.getByRole('heading', { name: /data unavailable/i })).toBeInTheDocument();
    expect(screen.queryByText(/unitCensus/)).not.toBeInTheDocument();
    expect(spy).toHaveBeenCalledWith('[CommandCenter] data unavailable:', detail);
    spy.mockRestore();
  });

  it('calls onRetry from the fallback button', () => {
    const onRetry = vi.fn();
    render(<CommandCenterError onRetry={onRetry} />);
    fireEvent.click(screen.getByRole('button', { name: /retry/i }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });
});
