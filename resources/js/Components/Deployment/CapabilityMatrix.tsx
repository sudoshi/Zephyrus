import type { MatrixCell } from '@/features/deployment/types';
import { EmptyState } from '@/Components/system';
import { capabilityLevelClass, ReviewPill } from './status';
import { humanize } from './format';

const TH = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
const TD = 'px-3 py-2 align-middle text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark';

export function CapabilityMatrix({ cells }: { cells: MatrixCell[] }) {
  if (cells.length === 0) {
    return <EmptyState message="No capability matrix rows for this facility yet." icon="heroicons:squares-2x2" />;
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <table className="w-full min-w-[42rem] border-collapse">
        <thead>
          <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
            <th className={TH}>Service line</th>
            <th className={TH}>Capability</th>
            <th className={TH}>Coverage</th>
            <th className={TH}>Evidence</th>
            <th className={TH}>Trust</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
          {cells.map((cell) => (
            <tr key={cell.service_line_code} className="transition-colors duration-150 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark">
              <td className={TD}>
                <div className="font-medium">{cell.service_line_name ?? humanize(cell.service_line_code)}</div>
                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {humanize(cell.clinical_domain)}
                </div>
              </td>
              <td className={TD}>
                <span className={capabilityLevelClass(cell.capability_rank)}>
                  {humanize(cell.capability_level) || '—'}
                </span>
              </td>
              <td className={`${TD} text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}>
                {humanize(cell.coverage_model)}
                {cell.hours ? <span className="ml-1 text-xs">· {cell.hours}</span> : null}
              </td>
              <td className={`${TD} text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}>
                {humanize(cell.source_evidence_type)}
              </td>
              <td className={TD}>
                <ReviewPill status={cell.review_status} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
