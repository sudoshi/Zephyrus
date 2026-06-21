import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import axios from 'axios';
import UnitHuddle from '@/Pages/RTDC/UnitHuddle';

vi.mock('axios');
vi.mock('@/lib/echo', () => ({
  echo: {
    channel: () => ({ listen: vi.fn() }),
    leaveChannel: vi.fn(),
    connector: { pusher: { connection: { bind: vi.fn(), unbind: vi.fn() } } },
  },
}));
// RTDCPageLayout renders DashboardLayout/TopNavigation, which require a
// DashboardProvider not present in unit tests. Stub it to a passthrough that
// still surfaces the live unit name (the page passes it as part of the title)
// as its own queryable node so we can assert on it in isolation.
vi.mock('@/Components/RTDC/RTDCPageLayout', () => ({
  default: ({ title, children }: { title: string; children: React.ReactNode }) => (
    <div>
      {title.split(' — ').map((part: string) => (
        <h1 key={part}>{part}</h1>
      ))}
      {children}
    </div>
  ),
}));
const mocked = vi.mocked(axios, true);

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('UnitHuddle (live)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders live census from the API and the bed-need readout', async () => {
    mocked.get.mockImplementation((url: string) => {
      if (url === '/api/rtdc/units') {
        return Promise.resolve({ data: { data: [{ unit_id: 1, name: '5 East', type: 'med_surg', staffed_bed_count: 32, census: { occupied: 20, available: 10, blocked: 2, acuity_adjusted_capacity: 8 } }] } });
      }
      if (url.includes('/prediction')) {
        return Promise.resolve({ data: { data: { rtdc_prediction_id: 1, unit_id: 1, service_date: '2026-06-20', horizon: 'by_2pm', discharges_weighted: 2, demand_expected: 5, capacity_now: 2, bed_need: 3, status: 'open' } } });
      }
      if (url.includes('/barriers')) {
        return Promise.resolve({ data: { data: [] } });
      }
      return Promise.resolve({ data: { data: null } });
    });

    renderWithClient(<UnitHuddle unitId={1} />);

    await waitFor(() => expect(screen.getByText('5 East')).toBeInTheDocument());
    expect(screen.getByText(/short 3 beds/i)).toBeInTheDocument();
  });
});
