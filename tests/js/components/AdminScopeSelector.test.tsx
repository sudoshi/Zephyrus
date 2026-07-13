import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminScopeSelector from '@/Components/Admin/AdminScopeSelector';

const mocks = vi.hoisted(() => ({
  put: vi.fn(),
  remove: vi.fn(),
  current: null as Record<string, unknown> | null,
}));

vi.mock('@inertiajs/react', () => ({
  router: { put: mocks.put, delete: mocks.remove },
  usePage: () => ({
    props: {
      adminScope: {
        organizations: [
          { id: 1, key: 'IDN_ONE', name: 'IDN One' },
          { id: 2, key: 'IDN_TWO', name: 'IDN Two' },
        ],
        facilities: [
          { id: 10, organizationId: 1, key: 'FACILITY_ONE', name: 'Facility One' },
          { id: 20, organizationId: 2, key: 'FACILITY_TWO', name: 'Facility Two' },
        ],
        sources: [
          { id: 100, organizationId: 1, facilityId: 10, key: 'epic.one', name: 'Epic One' },
          { id: 200, organizationId: 2, facilityId: 20, key: 'epic.two', name: 'Epic Two' },
        ],
        current: mocks.current,
        query: {},
        updateUrl: '/admin/active-scope',
        clearUrl: '/admin/active-scope',
      },
    },
  }),
}));

describe('AdminScopeSelector', () => {
  beforeEach(() => {
    mocks.current = null;
    mocks.put.mockReset();
    mocks.remove.mockReset();
  });

  it('never auto-selects and sends only a coherent hierarchy on apply', () => {
    render(<AdminScopeSelector />);

    expect(screen.getByLabelText('Active organization')).toHaveValue('');
    expect(screen.getByRole('button', { name: 'Apply' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Active organization'), { target: { value: '1' } });
    expect(screen.getByRole('option', { name: 'Facility One' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Facility Two' })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Active facility'), { target: { value: '10' } });
    expect(screen.getByRole('option', { name: 'Epic One' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Epic Two' })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Active integration source'), { target: { value: '100' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

    expect(mocks.put).toHaveBeenCalledWith('/admin/active-scope', {
      organization_id: 1,
      facility_id: 10,
      source_id: 100,
      return_path: '/',
    }, expect.objectContaining({ preserveScroll: true }));
  });

  it('renders the current boundary and supports explicit clearing', () => {
    mocks.current = {
      organization: { id: 1, key: 'IDN_ONE', name: 'IDN One' },
      facility: { id: 10, key: 'FACILITY_ONE', name: 'Facility One' },
      source: { id: 100, key: 'epic.one', name: 'Epic One' },
      revision: '019f0000-0000-7000-8000-000000000001',
      selectedAt: '2026-07-13T12:00:00Z',
    };
    render(<AdminScopeSelector />);

    expect(screen.getByLabelText('Active organization')).toHaveValue('1');
    expect(screen.getByLabelText('Active facility')).toHaveValue('10');
    expect(screen.getByLabelText('Active integration source')).toHaveValue('100');
    fireEvent.click(screen.getByRole('button', { name: 'Clear' }));

    expect(mocks.remove).toHaveBeenCalledWith('/admin/active-scope', expect.objectContaining({
      data: { return_path: '/' },
      preserveScroll: true,
    }));
  });
});
