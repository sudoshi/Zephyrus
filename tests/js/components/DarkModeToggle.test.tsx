import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';

describe('DarkModeToggle', () => {
  it('renders a bundled local icon and invokes the mode toggle', () => {
    const onToggle = vi.fn();
    const { container, rerender } = render(<DarkModeToggle isDarkMode onToggle={onToggle} />);

    const button = screen.getByRole('button', { name: 'Switch to light mode' });
    expect(container.querySelector('svg.lucide-sun')).toBeInTheDocument();
    fireEvent.click(button);
    expect(onToggle).toHaveBeenCalledOnce();

    rerender(<DarkModeToggle isDarkMode={false} onToggle={onToggle} />);
    expect(screen.getByRole('button', { name: 'Switch to dark mode' })).toBeInTheDocument();
    expect(container.querySelector('svg.lucide-moon')).toBeInTheDocument();
  });
});
