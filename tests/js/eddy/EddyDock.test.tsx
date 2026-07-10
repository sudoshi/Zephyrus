import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render } from '@testing-library/react';

const mockPage = vi.fn();
vi.mock('@inertiajs/react', () => ({ usePage: () => mockPage() }));

import { EddyDock } from '@/Components/Eddy/EddyDock';

const user = { id: 1, username: 'u', name: 'Operator', email: 'u@x.io', must_change_password: false };

describe('EddyDock visibility gate', () => {
  beforeEach(() => {
    mockPage.mockReset();
    window.history.replaceState({}, '', '/dashboard');
  });

  it('renders nothing when Eddy is disabled (ships disabled)', () => {
    mockPage.mockReturnValue({ props: { auth: { user }, eddy: { enabled: false } } });
    const { container } = render(<EddyDock />);
    expect(container.firstChild).toBeNull();
  });

  it('renders nothing when the eddy prop is absent (defaults disabled)', () => {
    mockPage.mockReturnValue({ props: { auth: { user } } });
    const { container } = render(<EddyDock />);
    expect(container.firstChild).toBeNull();
  });

  it('renders the launcher when enabled and authenticated', () => {
    mockPage.mockReturnValue({ props: { auth: { user }, eddy: { enabled: true } } });
    const { getByLabelText } = render(<EddyDock />);
    expect(getByLabelText('Ask Eddy')).toBeTruthy();
  });

  it('renders nothing for a guest even when enabled', () => {
    mockPage.mockReturnValue({ props: { auth: { user: null }, eddy: { enabled: true } } });
    const { container } = render(<EddyDock />);
    expect(container.firstChild).toBeNull();
  });

  it('renders nothing on a wall display even when enabled and authenticated', () => {
    window.history.replaceState({}, '', '/dashboard?display=wall');
    mockPage.mockReturnValue({ props: { auth: { user }, eddy: { enabled: true } } });

    const { container } = render(<EddyDock />);
    expect(container.firstChild).toBeNull();
  });
});
