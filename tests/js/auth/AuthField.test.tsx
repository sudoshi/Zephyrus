import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { AuthField } from '@/Components/Auth/AuthField';

describe('AuthField', () => {
  it('renders the label and placeholder', () => {
    render(
      <AuthField id="username" label="Username" icon="lucide:user"
        value="" onChange={() => {}} placeholder="Enter your username" />,
    );
    expect(screen.getByText('Username')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('Enter your username')).toBeInTheDocument();
  });

  it('calls onChange with the typed value', () => {
    const onChange = vi.fn();
    render(<AuthField id="username" label="Username" icon="lucide:user" value="" onChange={onChange} />);
    fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'drsmu' } });
    expect(onChange).toHaveBeenCalledWith('drsmu');
  });

  it('toggles password visibility when revealable', () => {
    render(
      <AuthField id="password" label="Password" icon="lucide:lock" type="password"
        revealable value="secret" onChange={() => {}} />,
    );
    const input = screen.getByLabelText('Password') as HTMLInputElement;
    expect(input.type).toBe('password');
    fireEvent.click(screen.getByRole('button', { name: /show password/i }));
    expect(input.type).toBe('text');
    fireEvent.click(screen.getByRole('button', { name: /hide password/i }));
    expect(input.type).toBe('password');
  });

  it('renders an optional suffix when optional', () => {
    render(<AuthField id="phone" label="Phone" icon="lucide:phone" optional value="" onChange={() => {}} />);
    expect(screen.getByText(/optional/i)).toBeInTheDocument();
  });
});
