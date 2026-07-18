import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { useBedMeeting, useLiveCensus } from '@/features/rtdc/hooks';
import { Section, MetricGrid, Panel, EmptyState, metric, STATUS_VAR } from '@/Components/system';
import { usePage } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';

const TODAY = new Date().toISOString().slice(0, 10);

interface DecantPayload {
  available: boolean;
  freeSlotsNow: number;
  freeSlots24h: number;
  freeSlots48h: number;
  edEligibleNow: number;
  stepDownEligibleNow: number;
  activeEpisodes: number;
  avoidedBedDaysMtd: number;
}

/** The home decant line (ACUM-PRD-HAH-001 §6.2) — mounted only when the
 *  Home Hospital flag is on; the huddle is byte-identical without it. */
function useHomeDecant(enabled: boolean) {
  return useQuery<DecantPayload>({
    queryKey: ['home', 'decant'],
    queryFn: async () => (await axios.get('/api/home/decant')).data,
    enabled,
    refetchInterval: 60_000,
  });
}

export default function GlobalHuddle() {
  useLiveCensus();
  const { data: rollup, isLoading } = useBedMeeting(TODAY, 'by_2pm');
  const features = (usePage().props as { features?: { home_hospital?: boolean } }).features;
  const { data: decant } = useHomeDecant(features?.home_hospital === true);

  const netBedNeed = rollup?.net_bed_need ?? 0;
  const totalDeficit = rollup?.total_positive_bed_need ?? 0;

  const kpiMetrics = [
    metric({
      key: 'net-bed-need',
      label: 'Net Bed Need',
      value: netBedNeed,
      display: rollup ? String(netBedNeed) : '—',
      goodWhenDown: true,
      status: netBedNeed > 0 ? 'warning' : 'success',
      caption: netBedNeed > 0 ? 'System running short of beds by 2pm' : 'Capacity meets expected demand',
      definition: 'System-wide expected demand minus available capacity by 2pm.',
    }),
    metric({
      key: 'total-deficit',
      label: 'Total Deficit (units short)',
      value: totalDeficit,
      display: rollup ? String(totalDeficit) : '—',
      goodWhenDown: true,
      status: totalDeficit > 0 ? 'critical' : 'success',
      caption: 'Sum of unit-level bed shortfalls',
      definition: 'Total positive bed need summed across units running short.',
    }),
  ];

  const decantMetrics = decant?.available
    ? [
        metric({
          key: 'home-eligible',
          label: 'Home-eligible now',
          value: decant.edEligibleNow + decant.stepDownEligibleNow,
          display: `${decant.edEligibleNow + decant.stepDownEligibleNow}`,
          status: 'info',
          caption: `${decant.edEligibleNow} in the ED · ${decant.stepDownEligibleNow} step-down`,
          definition: 'Patients on the live census screening home-eligible — each enrollment relieves a physical bed.',
          drillHref: '/home/referrals',
        }),
        metric({
          key: 'home-free-slots',
          label: 'Free home slots tonight',
          value: decant.freeSlotsNow,
          status: decant.freeSlotsNow === 0 ? 'warning' : 'success',
          caption: `${decant.freeSlots24h} by tomorrow · ${decant.freeSlots48h} by 48h`,
          definition: 'Open virtual-ward slots — the decant valve on house capacity.',
          drillHref: '/home/census',
        }),
        metric({
          key: 'home-avoided',
          label: 'Avoided bed-days MTD',
          value: decant.avoidedBedDaysMtd,
          status: 'neutral',
          caption: `${decant.activeEpisodes} active home episodes`,
          definition: 'Active home episode-days this month — occupied bed-days the house did not spend.',
        }),
      ]
    : [];

  return (
    <RTDCPageLayout title="Hospital Bed Meeting" subtitle="Real-Time Demand Capacity — system roll-up">
      <div className="flex flex-col gap-5">
        <Section title="System roll-up" icon="heroicons:building-office-2" summary="Net bed need and total deficit by 2pm">
          <MetricGrid metrics={kpiMetrics} />
        </Section>

        {decantMetrics.length > 0 && (
          <Section
            title="Home decant"
            icon="heroicons:home-modern"
            summary="Home-eligible census vs free virtual-ward slots — enroll these and boarding hours fall"
            drillHref="/home/referrals"
            drillLabel="Referrals"
          >
            <MetricGrid metrics={decantMetrics} />
          </Section>
        )}

        <Section title="Unit bed need" icon="heroicons:table-cells" summary="Capacity vs expected demand per unit">
          <Panel className="p-4">
            {isLoading ? (
              <EmptyState message="Loading roll-up…" icon="heroicons:arrow-path" />
            ) : rollup && rollup.units.length > 0 ? (
              <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                <thead>
                  <tr>
                    {['Unit', 'Capacity', 'Demand', 'Bed Need'].map((h, i) => (
                      <th key={h} className={`px-3 py-2 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ${i === 0 ? 'text-left' : 'text-right'}`}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {rollup.units.map((u) => (
                    <tr key={u.unit_id} className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-200">
                      <td className="px-3 py-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark whitespace-nowrap">{u.unit_name}</td>
                      <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{u.capacity_now}</td>
                      <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{u.demand_expected}</td>
                      <td className="px-3 py-2 text-sm text-right tabular-nums font-semibold whitespace-nowrap" style={{ color: STATUS_VAR[u.bed_need > 0 ? 'critical' : 'success'] }}>
                        {u.bed_need > 0 ? `+${u.bed_need}` : u.bed_need}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <EmptyState message="No unit roll-up available." icon="heroicons:table-cells" />
            )}
          </Panel>
        </Section>
      </div>
    </RTDCPageLayout>
  );
}
