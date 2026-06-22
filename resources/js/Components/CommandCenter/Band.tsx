// resources/js/Components/CommandCenter/Band.tsx
import { Link } from '@inertiajs/react';
import type { BandData, KpiMetric } from '@/types/commandCenter';
import { KpiTile } from './KpiTile';

const GRID = 'repeat(auto-fit, minmax(150px, 1fr))';

function TileGrid({ metrics }: { metrics: KpiMetric[] }) {
  return (
    <div className="grid gap-2" style={{ gridTemplateColumns: GRID }}>
      {metrics.map((m) => <KpiTile key={m.key} metric={m} />)}
    </div>
  );
}

export function Band({ band }: { band: BandData }) {
  return (
    <section aria-label={band.title} className="flex flex-col gap-2">
      <header className="flex items-center justify-between gap-3 border-b border-healthcare-border dark:border-healthcare-border-dark pb-1 transition-colors duration-300">
        <div className="flex items-baseline gap-3">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {band.title}
          </h2>
          <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{band.summary}</span>
        </div>
        <Link href={band.drillHref}
              className="whitespace-nowrap text-xs font-medium text-healthcare-primary dark:text-healthcare-primary-dark hover:underline">
          {band.drillLabel} {'→'}
        </Link>
      </header>

      {band.subgroups ? (
        <div className="flex flex-col gap-2">
          {band.subgroups.map((g) => (
            <div key={g.key} className="flex flex-col gap-1">
              <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{g.label}</span>
              <TileGrid metrics={g.metrics} />
            </div>
          ))}
        </div>
      ) : (
        <TileGrid metrics={band.metrics} />
      )}
    </section>
  );
}
