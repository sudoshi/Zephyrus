import HomePageLayout from '@/Components/Home/HomePageLayout';
import { Section } from '@/Components/system';

// Field Operations & Logistics (ACUM-PRD-HAH-001 §4.2). ⚠ THE ONE ADDRESS
// SURFACE: patient street addresses render here and nowhere else in the
// module — every other Home payload carries pseudonymous refs + zones only.

interface RailVisit {
  visitUuid: string;
  type: string;
  status: string;
  scheduledStart: string | null;
  assignedTo: string | null;
  isWaiverRequired: boolean;
  onTime: boolean | null;
}

interface RailRow {
  patientRef: string;
  serviceZone: string | null;
  address: string | null;
  waiverRequired: number;
  waiverCompleted: number;
  waiverScheduled: number;
  compliant: boolean;
  visits: RailVisit[];
}

interface AssignmentStop {
  patientRef: string;
  type: string;
  scheduledStart: string | null;
  serviceZone: string | null;
  address: string | null;
}

interface Assignment {
  assignee: string;
  stops: AssignmentStop[];
}

interface Delivery {
  visitUuid: string;
  patientRef: string;
  type: string;
  status: string;
  scheduledStart: string | null;
}

interface LogisticsProps {
  complianceRail: RailRow[];
  assignments: Assignment[];
  kits: { byStatus: Record<string, number>; lowBattery: number };
  deliveries: Delivery[];
}

const VISIT_LABELS: Record<string, string> = {
  rn: 'RN',
  community_paramedic: 'Paramedic',
  md_np_tele: 'MD/NP tele',
  md_np_in_person: 'MD/NP',
  lab_draw: 'Lab draw',
  delivery: 'Delivery',
  other: 'Visit',
};

const surface =
  'rounded-lg border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm';

function timeOf(iso: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

export default function Logistics({ complianceRail, assignments, kits, deliveries }: LogisticsProps) {
  const compliant = complianceRail.filter((r) => r.compliant).length;

  return (
    <HomePageLayout
      title="Field Ops & Logistics"
      subtitle={`${compliant}/${complianceRail.length} episodes on the 2-visit waiver rail · ${kits.lowBattery} kits low battery`}
    >
      <div className="flex flex-col gap-5">
        <Section
          title="Waiver compliance rail"
          icon="heroicons:clipboard-document-check"
          summary="≥2 in-person visits per day, per episode — the AHCAH operating floor"
        >
          <div className={`${surface} overflow-x-auto`}>
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark text-left text-xs uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  <th className="px-4 py-2 font-medium">Patient</th>
                  <th className="px-4 py-2 font-medium">Zone</th>
                  <th className="px-4 py-2 font-medium">Address</th>
                  <th className="px-4 py-2 font-medium text-right">Waiver visits</th>
                  <th className="px-4 py-2 font-medium text-right">Rail</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                {complianceRail.map((row) => (
                  <tr key={row.patientRef}>
                    <td className="px-4 py-2 tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {row.patientRef}
                    </td>
                    <td className="px-4 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {row.serviceZone ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {row.address ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {row.waiverCompleted} done · {row.waiverScheduled} scheduled / {row.waiverRequired}
                    </td>
                    <td className="px-4 py-2 text-right">
                      <span
                        className={`text-xs font-medium ${
                          row.compliant
                            ? 'text-healthcare-success dark:text-healthcare-success-dark'
                            : 'text-healthcare-warning dark:text-healthcare-warning-dark'
                        }`}
                      >
                        {row.compliant ? '● on rail' : '▲ at risk'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Section>

        <Section
          title="Field assignments"
          icon="heroicons:map"
          summary="Today's routes by clinician — staffing-model-agnostic"
        >
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
            {assignments.length === 0 && (
              <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                No field visits remaining today.
              </p>
            )}
            {assignments.map((a) => (
              <div key={a.assignee} className={`${surface} p-3 flex flex-col gap-1.5`}>
                <span className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {a.assignee}
                </span>
                {a.stops.map((stop, i) => (
                  <div key={`${stop.patientRef}-${i}`} className="flex flex-col">
                    <span className="text-xs tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {timeOf(stop.scheduledStart)} · {stop.patientRef} · {VISIT_LABELS[stop.type] ?? stop.type}
                    </span>
                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {stop.address ?? stop.serviceZone ?? '—'}
                    </span>
                  </div>
                ))}
              </div>
            ))}
          </div>
        </Section>

        <Section
          title="Kits & deliveries"
          icon="heroicons:cube"
          summary="RPM kit lifecycle and last-24h deliveries"
        >
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
            <div className={`${surface} p-3`}>
              <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Kit inventory
              </h3>
              <div className="mt-2 flex flex-col gap-1">
                {Object.entries(kits.byStatus).map(([status, count]) => (
                  <div key={status} className="flex items-center justify-between">
                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {status.replaceAll('_', ' ')}
                    </span>
                    <span className="text-xs font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {count}
                    </span>
                  </div>
                ))}
                <div className="flex items-center justify-between border-t border-healthcare-border dark:border-healthcare-border-dark pt-1">
                  <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    low battery (&lt;30%)
                  </span>
                  <span className="text-xs font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {kits.lowBattery}
                  </span>
                </div>
              </div>
            </div>
            <div className={`${surface} p-3`}>
              <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Deliveries & draws
              </h3>
              <div className="mt-2 flex flex-col gap-1">
                {deliveries.length === 0 && (
                  <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Nothing scheduled in the last 24 hours.
                  </p>
                )}
                {deliveries.map((d) => (
                  <div key={d.visitUuid} className="flex items-center justify-between gap-2">
                    <span className="text-xs tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {d.patientRef}
                    </span>
                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {VISIT_LABELS[d.type] ?? d.type} · {d.status} · {timeOf(d.scheduledStart)}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </Section>
      </div>
    </HomePageLayout>
  );
}
