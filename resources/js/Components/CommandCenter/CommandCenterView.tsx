// resources/js/Components/CommandCenter/CommandCenterView.tsx
import type { CommandCenterData } from '@/types/commandCenter';
import { useCommandCenterStore } from '@/stores/commandCenterStore';
import { HeroWall } from './HeroWall';
import { Band } from './Band';
import { UnitHeatStrip } from './UnitHeatStrip';
import { ForecastCurve } from './ForecastCurve';

interface CommandCenterViewProps {
  data: CommandCenterData;
  onRefresh: () => void;
  updatedLabel: string;
  refreshing?: boolean;
  aging?: boolean;
  stale?: boolean;
}

export function CommandCenterView({
  data,
  onRefresh,
  updatedLabel,
  refreshing = false,
  aging = false,
  stale = false,
}: CommandCenterViewProps) {
  const role = useCommandCenterStore((s) => s.role);

  // Stale onset + recovery are announced app-chrome-wide by CommandCenter now
  // (P8 WS-6b) so every mount — house, scoped, wall — gets it, not just this view.

  // Adaptive emphasis by role re-orders the bands and toggles the unit heat
  // strip, but NEVER strips information: every tile shows its sparkline + detail
  // breakdown in every role (density with clarity, per PRODUCT.md). Role changes
  // arrangement and lead, not how much each panel says.
  const showHeatStrip = role !== 'executive';
  const detailed = true;
  const bands = role === 'executive'
    ? [data.outcomes, data.forecast, data.capacity, data.flow]
    : [data.capacity, data.flow, data.outcomes, data.forecast];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-end gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <span className="inline-flex items-center gap-1.5">
          {(aging || stale) && (
            <span aria-hidden="true"
                  className="h-1.5 w-1.5 shrink-0 rounded-full bg-healthcare-warning dark:bg-healthcare-warning-dark" />
          )}
          Updated {updatedLabel}
        </span>
        <button type="button" onClick={onRefresh} disabled={refreshing} aria-label="Refresh data"
                className="inline-flex items-center gap-1 rounded-md border border-healthcare-border dark:border-healthcare-border-dark
                           bg-healthcare-surface dark:bg-healthcare-surface-dark
                           px-2 py-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark
                           shadow-sm transition-colors duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark
                           disabled:cursor-not-allowed disabled:opacity-60">
          <span className={refreshing ? 'inline-block motion-safe:animate-spin' : 'inline-block'} aria-hidden="true">{'⟳'}</span>
          {refreshing ? 'Refreshing…' : 'Refresh'}
        </button>
      </div>

      {/* The loud stale banner now lives app-chrome-wide (StaleDataBanner in
          CommandCenter) so it fires at every scope. This view keeps the subtle
          aging dot above and the sr-only recovery announcement. */}

      <HeroWall role={role} strain={data.strain} heroMetrics={data.heroMetrics}
                objectives={data.objectives} detailed={detailed} />

      {bands.map((band) => (
        <div key={band.key} className="flex flex-col gap-2">
          <Band band={band} detailed={detailed} />
          {band.key === 'capacity' && showHeatStrip && <UnitHeatStrip units={data.unitCensus} />}
          {band.key === 'forecast' && <ForecastCurve forecast={data.forecastDetail} />}
        </div>
      ))}
    </div>
  );
}
