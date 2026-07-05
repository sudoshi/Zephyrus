// tests/js/cockpit/ScopePicker.test.tsx
//
// P8 WS-5 — the mount scope picker consumes GET /api/cockpit/scopes and offers
// every altitude the caller may mount, grouped (House / My units / Units /
// Departments / Service lines). Selecting one navigates with a full page load to
// the matching ?scope= (or /dashboard for house) so the read-once mount resets.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ScopePicker } from '@/Components/cockpit/ScopePicker';
import { fetchCockpitScopes } from '@/features/cockpit/scopeApi';

vi.mock('@/features/cockpit/scopeApi', () => ({
  fetchCockpitScopes: vi.fn(),
}));
const mockedFetch = vi.mocked(fetchCockpitScopes);

const scopesPayload = {
  active: { level: 'house', key: null, label: 'Summit Regional', token: 'house' },
  catalog: {
    house: { level: 'house', key: null, label: 'Summit Regional', token: 'house' },
    departments: [
      { level: 'department', key: 'ed', label: 'Emergency Department', token: 'department:ed' },
    ],
    serviceLines: [
      { level: 'service_line', key: 'critical_care', label: 'Critical Care', token: 'service_line:critical_care' },
    ],
    units: [
      { level: 'unit', key: 'MICU', label: 'Medical ICU', token: 'unit:MICU', assigned: true },
      { level: 'unit', key: 'SICU', label: 'Surgical ICU', token: 'unit:SICU', assigned: false },
    ],
  },
};

function renderPicker(activeToken: string | null = null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ScopePicker activeToken={activeToken} />
    </QueryClientProvider>,
  );
}

describe('ScopePicker', () => {
  let assign: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.clearAllMocks();
    assign = vi.fn();
    Object.defineProperty(window, 'location', {
      value: { ...window.location, assign, search: '' },
      writable: true,
      configurable: true,
    });
  });

  it('renders the catalog grouped, foregrounding assigned units', async () => {
    mockedFetch.mockResolvedValue(scopesPayload);
    const { container } = renderPicker();

    // Wait for the loaded state — the loading stub shares the "Mount scope" label,
    // so we key off an option that only exists once the catalog resolves.
    await screen.findByRole('option', { name: 'Medical ICU' });

    const myUnits = container.querySelector<HTMLElement>('optgroup[label="My units"]');
    expect(myUnits).not.toBeNull();
    expect(within(myUnits as HTMLElement).getByRole('option', { name: 'Medical ICU' })).toBeInTheDocument();

    const units = container.querySelector<HTMLElement>('optgroup[label="Units"]');
    expect(within(units as HTMLElement).getByRole('option', { name: 'Surgical ICU' })).toBeInTheDocument();

    expect(container.querySelector('optgroup[label="Departments"]')).not.toBeNull();
    expect(container.querySelector('optgroup[label="Service lines"]')).not.toBeNull();
  });

  it('navigates to the ?scope= mount when a unit is chosen', async () => {
    mockedFetch.mockResolvedValue(scopesPayload);
    renderPicker();

    await screen.findByRole('option', { name: 'Medical ICU' });
    const select = screen.getByRole('combobox', { name: 'Mount scope' });
    fireEvent.change(select, { target: { value: 'unit:MICU' } });
    expect(assign).toHaveBeenCalledWith('/dashboard?scope=unit%3AMICU');
  });

  it('navigates to the bare dashboard when house is chosen', async () => {
    mockedFetch.mockResolvedValue(scopesPayload);
    renderPicker('unit:MICU');

    await screen.findByRole('option', { name: 'Summit Regional' });
    const select = screen.getByRole('combobox', { name: 'Mount scope' });
    fireEvent.change(select, { target: { value: 'house' } });
    expect(assign).toHaveBeenCalledWith('/dashboard');
  });

  it('fails quiet (renders nothing) when the catalog payload breaks contract', async () => {
    mockedFetch.mockResolvedValue({ not: 'a catalog' });
    const { container } = renderPicker();
    // Starts as the loading stub, then resolves to a broken payload → renders null.
    await waitFor(() => expect(container).toBeEmptyDOMElement());
    expect(screen.queryByRole('combobox', { name: 'Mount scope' })).not.toBeInTheDocument();
  });
});
