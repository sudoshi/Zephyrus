import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { useBedMeeting, useLiveCensus } from '@/features/rtdc/hooks';

const TODAY = new Date().toISOString().slice(0, 10);

export default function GlobalHuddle() {
  useLiveCensus();
  const { data: rollup, isLoading } = useBedMeeting(TODAY, 'by_2pm');

  return (
    <RTDCPageLayout title="Hospital Bed Meeting" subtitle="Real-Time Demand Capacity — system roll-up">
      <div className="flex flex-col gap-6">
        <div className="grid grid-cols-2 gap-4">
          <div className="rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark p-5">
            <div className="text-label">Net Bed Need</div>
            <div className="text-2xl font-semibold tabular-nums">{rollup ? rollup.net_bed_need : '—'}</div>
          </div>
          <div className="rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark p-5">
            <div className="text-label">Total Deficit (units short)</div>
            <div className="text-2xl font-semibold tabular-nums text-[var(--critical)]">{rollup ? rollup.total_positive_bed_need : '—'}</div>
          </div>
        </div>

        {isLoading && <div className="text-caption">Loading roll-up…</div>}

        <table className="w-full text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <thead>
            <tr className="text-label text-left">
              <th className="p-2">Unit</th>
              <th className="p-2">Capacity</th>
              <th className="p-2">Demand</th>
              <th className="p-2">Bed Need</th>
            </tr>
          </thead>
          <tbody>
            {rollup?.units.map((u) => (
              <tr key={u.unit_id} className="border-t border-healthcare-border dark:border-healthcare-border-dark">
                <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{u.unit_name}</td>
                <td className="p-2">{u.capacity_now}</td>
                <td className="p-2">{u.demand_expected}</td>
                <td className={`p-2 ${u.bed_need > 0 ? 'text-[var(--critical)]' : 'text-[var(--success)]'}`}>
                  {u.bed_need > 0 ? `+${u.bed_need}` : u.bed_need}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </RTDCPageLayout>
  );
}
