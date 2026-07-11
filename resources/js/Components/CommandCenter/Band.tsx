// resources/js/Components/CommandCenter/Band.tsx
import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import type { BandData, KpiMetric } from '@/types/commandCenter';
import { KpiTile } from './KpiTile';
import { EmptyState } from './states';

const GRID = 'repeat(auto-fit, minmax(168px, 1fr))';

const BAND_ICON: Record<BandData['key'], string> = {
  capacity: 'heroicons:building-office-2',
  flow: 'heroicons:arrows-right-left',
  outcomes: 'heroicons:shield-check',
  forecast: 'heroicons:presentation-chart-line',
};

function TileGrid({
  metrics,
  emptyLabel,
  detailed,
  interactive,
}: {
  metrics: KpiMetric[];
  emptyLabel: string;
  detailed: boolean;
  interactive: boolean;
}) {
  if (metrics.length === 0) return <EmptyState message={emptyLabel} />;
  return (
    <div className="grid gap-2" style={{ gridTemplateColumns: GRID }}>
      {metrics.map((m) => <KpiTile key={m.key} metric={m} detailed={detailed} interactive={interactive} />)}
    </div>
  );
}

export function Band({
  band,
  detailed = false,
  interactive = true,
}: {
  band: BandData;
  detailed?: boolean;
  /** False for a wall: suppress the band CTA and every metric drill link. */
  interactive?: boolean;
}) {
  return (
    <section aria-label={band.title} className="flex flex-col gap-2">
      <header className="flex items-center justify-between gap-3 border-b border-healthcare-border dark:border-healthcare-border-dark pb-1 transition-colors duration-300">
        <div className="flex items-baseline gap-3">
          <span className="flex items-center gap-2">
            <Icon icon={BAND_ICON[band.key]} aria-hidden="true"
                  className="h-4 w-4 shrink-0 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <h2 className="text-sm font-semibold uppercase tracking-wide text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {band.title}
            </h2>
          </span>
          <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{band.summary}</span>
        </div>
        {interactive && (
          <Link href={band.drillHref}
                className="whitespace-nowrap text-xs font-medium text-healthcare-primary dark:text-healthcare-primary-dark hover:underline">
            {band.drillLabel} {'→'}
          </Link>
        )}
      </header>

      {band.subgroups && band.subgroups.length > 0 ? (
        <div className="flex flex-col gap-2">
          {band.subgroups.map((g) => (
            <div key={g.key} className="flex flex-col gap-1">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{g.label}</span>
              <TileGrid metrics={g.metrics} emptyLabel={`No ${g.label} metrics reporting`} detailed={detailed} interactive={interactive} />
            </div>
          ))}
        </div>
      ) : (
        <TileGrid metrics={band.metrics} emptyLabel={`No ${band.title.toLowerCase()} metrics reporting`} detailed={detailed} interactive={interactive} />
      )}
    </section>
  );
}
