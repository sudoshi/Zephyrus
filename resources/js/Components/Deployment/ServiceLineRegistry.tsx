import type { ServiceLineCatalog } from '@/features/deployment/types';
import { capabilityLevelClass } from './status';
import { humanize } from './format';

const TH = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
const TD = 'px-3 py-2 align-middle text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark';

export function ServiceLineRegistry({ catalog }: { catalog: ServiceLineCatalog }) {
  const levels = [...catalog.capability_levels].sort((a, b) => a.rank - b.rank);

  return (
    <div className="space-y-4">
      {/* Capability ladder — the shared vocabulary the matrix scores against. */}
      <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Capability ladder
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          {levels.map((lvl, i) => (
            <span key={lvl.code} className="flex items-center gap-1.5">
              <span className={capabilityLevelClass(lvl.rank)}>
                {lvl.display_name}
              </span>
              {i < levels.length - 1 && (
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true">
                  →
                </span>
              )}
            </span>
          ))}
        </div>
      </div>

      {/* Service-line catalog */}
      <div className="overflow-x-auto rounded-lg border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <table className="w-full min-w-[44rem] border-collapse">
          <thead>
            <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
              <th className={TH}>Service line</th>
              <th className={TH}>Domain</th>
              <th className={TH}>Population</th>
              <th className={TH}>Setting</th>
              <th className={TH}>Workflow</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            {catalog.service_lines.map((sl) => (
              <tr key={sl.code} className="transition-colors duration-150 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark">
                <td className={TD}>
                  <span className="font-medium">{sl.name}</span>
                </td>
                <td className={`${TD} text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}>
                  {humanize(sl.clinical_domain)}
                </td>
                <td className={`${TD} text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}>
                  {humanize(sl.adult_or_pediatric)}
                </td>
                <td className={`${TD} text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}>
                  {humanize(sl.care_setting_default)}
                </td>
                <td className={`${TD} text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}>
                  {humanize(sl.default_workflow)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
