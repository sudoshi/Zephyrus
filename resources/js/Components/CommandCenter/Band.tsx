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
      <header className="flex items-center justify-between gap-3 border-b pb-1"
              style={{ borderColor: 'var(--surface-elevated)' }}>
        <div className="flex items-baseline gap-3">
          <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: 'var(--text-primary)' }}>
            {band.title}
          </h2>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{band.summary}</span>
        </div>
        <Link href={band.drillHref} className="whitespace-nowrap text-xs" style={{ color: 'var(--accent)' }}>
          {band.drillLabel} {'→'}
        </Link>
      </header>

      {band.subgroups ? (
        <div className="flex flex-col gap-2">
          {band.subgroups.map((g) => (
            <div key={g.key} className="flex flex-col gap-1">
              <span className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{g.label}</span>
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
