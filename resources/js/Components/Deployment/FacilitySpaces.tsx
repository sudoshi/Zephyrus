import type { FacilitySpace } from '@/features/deployment/types';
import { EmptyState } from '@/Components/system';
import { Link2, Unlink } from 'lucide-react';
import { StatusPill } from './status';
import { humanize } from './format';

const TH = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
const TD = 'px-3 py-2 align-top text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark';
const CHIP = 'inline-flex items-center rounded bg-healthcare-background px-1.5 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark';

export function FacilitySpaces({ spaces }: { spaces: FacilitySpace[] }) {
  if (spaces.length === 0) {
    return <EmptyState message="No mapped facility spaces for this facility yet." icon="heroicons:building-office" />;
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <table className="w-full min-w-[48rem] border-collapse">
        <thead>
          <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
            <th className={TH}>Space</th>
            <th className={TH}>Service lines</th>
            <th className={TH}>Location role</th>
            <th className={TH}>Capability tags</th>
            <th className={TH}>Prod map</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
          {spaces.map((space) => {
            const mapped = space.operational_targets.length > 0;
            return (
              <tr key={space.space_code} className="transition-colors duration-150 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark">
                <td className={TD}>
                  <div className="font-medium tabular-nums">{space.space_code}</div>
                  <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {space.space_name ?? humanize(space.space_category)}
                    {space.floor_number !== null ? ` · Fl ${space.floor_number}` : ''}
                    {space.acuity_level ? ` · ${humanize(space.acuity_level)}` : ''}
                  </div>
                </td>
                <td className={TD}>
                  <div className="flex flex-wrap gap-1">
                    {space.service_lines.length > 0 ? (
                      space.service_lines.map((sl) => (
                        <span key={sl} className={CHIP}>
                          {humanize(sl)}
                        </span>
                      ))
                    ) : (
                      <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">—</span>
                    )}
                  </div>
                </td>
                <td className={`${TD} text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}>
                  {humanize(space.location_role)}
                </td>
                <td className={TD}>
                  <div className="flex flex-wrap gap-1">
                    {space.capability_tags.length > 0 ? (
                      space.capability_tags.map((tag) => (
                        <span key={tag} className={CHIP}>
                          {humanize(tag)}
                        </span>
                      ))
                    ) : (
                      <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">—</span>
                    )}
                  </div>
                </td>
                <td className={TD}>
                  {mapped ? (
                    <StatusPill
                      tone="success"
                      icon={Link2}
                      label={`Mapped · ${space.operational_targets.length}`}
                    />
                  ) : (
                    <StatusPill tone="neutral" icon={Unlink} label="Unmapped" />
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
