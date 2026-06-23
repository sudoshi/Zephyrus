import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AuthFormPanel } from '@/Components/Auth/AuthFormPanel';

describe('AuthFormPanel', () => {
  it('renders its children', () => {
    render(<AuthFormPanel><p>hello form</p></AuthFormPanel>);
    expect(screen.getByText('hello form')).toBeInTheDocument();
  });

  it('renders the animated shimmer spinner element', () => {
    const { container } = render(<AuthFormPanel><span /></AuthFormPanel>);
    expect(container.querySelector('.auth-shimmer__spin')).not.toBeNull();
  });
});
