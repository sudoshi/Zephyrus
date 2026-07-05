import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { EmptyState } from '@/Components/system';
import { humanize } from '@/Components/Deployment/format';
import { useStaffingCoverage } from '@/features/deployment/staffing/hooks';
import type { CommitResult } from '@/features/deployment/staffing/types';
import { BTN_GHOST } from './controls';

function Stat({ label, value, warn = false }: { label: string; value: number; warn?: boolean }) {
  return (
    <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className={`text-2xl font-semibold tabular-nums ${warn && value > 0 ? 'text-healthcare-warning dark:text-healthcare-warning-dark' : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'}`}>{value}</div>
      <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</div>
    </div>
  );
}

interface CoveragePanelProps {
  facilityKey: string;
  committed: CommitResult | null;
  onRestart: () => void;
}

export function CoveragePanel({ facilityKey, committed, onRestart }: CoveragePanelProps) {
  const coverage = useStaffingCoverage(facilityKey);
  const report = coverage.data;
  const s = committed?.summary;

  return (
    <div className="space-y-5">
      {committed && (
        <div className="flex items-center gap-3 rounded-lg border border-healthcare-success/30 bg-healthcare-success/5 p-4 dark:border-healthcare-success-dark/30 dark:bg-healthcare-success-dark/10">
          <CheckCircle2 className="size-6 shrink-0 text-healthcare-success dark:text-healthcare-success-dark" aria-hidden="true" />
          <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            <span className="font-semibold">Committed.</span>{' '}
            <span className="tabular-nums">{s?.members ?? 0}</span> members ·{' '}
            <span className="tabular-nums">{s?.assignments ?? 0}</span> assignments ·{' '}
            <span className="tabular-nums">{s?.provisioned ?? 0}</span> accounts provisioned
            {(s?.deactivated ?? 0) > 0 && <> · <span className="tabular-nums">{s?.deactivated}</span> deactivated</>}
          </div>
        </div>
      )}

      <div>
        <div className="mb-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Coverage — {humanize(facilityKey)}
        </div>
        {coverage.isLoading || !report ? (
          <div className="rounded-lg border border-healthcare-border p-6 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">Loading coverage…</div>
        ) : report.units.length === 0 ? (
          <EmptyState message="No mapped units for this facility yet — map facility spaces to see staffed/unstaffed coverage." icon="heroicons:building-office" />
        ) : (
          <div className="space-y-3">
            <div className="grid grid-cols-3 gap-3">
              <Stat label="Units total" value={report.summary.units_total} />
              <Stat label="Staffed" value={report.summary.units_staffed} />
              <Stat label="Unstaffed" value={report.summary.units_unstaffed} warn />
            </div>

            <div className="overflow-x-auto rounded-lg border border-healthcare-border dark:border-healthcare-border-dark">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-healthcare-border text-left text-xs uppercase tracking-wide text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                    <th className="px-3 py-2 font-medium">Service line</th>
                    <th className="px-3 py-2 font-medium">Units staffed</th>
                    <th className="px-3 py-2 font-medium">Assignments</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {report.service_lines.map((sl) => {
                    const gap = sl.units_total - sl.units_staffed;
                    return (
                      <tr key={sl.service_line_code ?? 'unassigned'}>
                        <td className="px-3 py-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{humanize(sl.service_line_code ?? 'Unassigned')}</td>
                        <td className="px-3 py-2 tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          <span className="inline-flex items-center gap-1.5">
                            {sl.units_staffed}/{sl.units_total}
                            {gap > 0 && <AlertTriangle className="size-3.5 text-healthcare-warning dark:text-healthcare-warning-dark" aria-label={`${gap} unstaffed`} />}
                          </span>
                        </td>
                        <td className="px-3 py-2 tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{sl.assignments}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      <div className="flex justify-end border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
        <button type="button" className={BTN_GHOST} onClick={onRestart}>Import another source</button>
      </div>
    </div>
  );
}
