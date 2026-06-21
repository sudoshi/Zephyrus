import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import axios from 'axios';
import GlobalHuddle from '@/Pages/RTDC/GlobalHuddle';

vi.mock('axios');
vi.mock('@/lib/echo', () => ({
  echo: { channel: () => ({ listen: vi.fn() }), leaveChannel: vi.fn(), connector: { pusher: { connection: { bind: vi.fn(), unbind: vi.fn() } } } },
}));
// RTDCPageLayout renders DashboardLayout/TopNavigation, which require a
// DashboardProvider not present in unit tests. Stub it to a passthrough so we
// can assert the page's own content in isolation.
vi.mock('@/Components/RTDC/RTDCPageLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
const mocked = vi.mocked(axios, true);

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('GlobalHuddle (live bed meeting)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows the net bed-need and per-unit rollup', async () => {
    mocked.get.mockResolvedValue({
      data: { data: { net_bed_need: 3, total_positive_bed_need: 5, units: [
        { unit_id: 1, unit_name: '5 East', bed_need: 3, capacity_now: 2, demand_expected: 5 },
        { unit_id: 2, unit_name: 'ICU', bed_need: -2, capacity_now: 4, demand_expected: 2 },
      ] } },
    });

    renderWithClient(<GlobalHuddle />);

    await waitFor(() => expect(screen.getByText('5 East')).toBeInTheDocument());
    expect(screen.getByText(/net bed need/i)).toBeInTheDocument();
    expect(screen.getByText('ICU')).toBeInTheDocument();
  });
});
