// resources/js/Components/CommandCenter/CommandCenterView.tsx
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@iconify/react';
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

  // Announce only the recovery transition (stale → fresh). The stale banner
  // already announces onset; routine 45s refreshes are deliberately silent so
  // screen-reader users aren't read a "data updated" line every cycle.
  const wasStale = useRef(stale);
  const [recoveryNote, setRecoveryNote] = useState('');
  useEffect(() => {
    if (wasStale.current && !stale) setRecoveryNote('Live updates resumed. Data is current.');
    wasStale.current = stale;
  }, [stale]);

  // Adaptive emphasis by role: the executive view leads with outcomes & forecast
  // and suppresses unit-level census noise; command leads with capacity & flow.
  // Command is the glance view (collapsed tiles); executive is the review view
  // (tiles expand to show sparkline + detail breakdown).
  const showHeatStrip = role !== 'executive';
  const detailed = role === 'executive';
  const bands = role === 'executive'
    ? [data.outcomes, data.forecast, data.capacity, data.flow]
    : [data.capacity, data.flow, data.outcomes, data.forecast];

  return (
    <div className="flex flex-col gap-4">
      <div className="sr-only" role="status" aria-live="polite" aria-label="Live update status">{recoveryNote}</div>

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

      {/* Stale signal: when the payload stops advancing, say so loudly and stop
          implying the numbers are live. role="status" announces it to SR users. */}
      {stale && (
        <div role="status" aria-live="polite" aria-label="Stale data warning"
             className="flex items-center gap-2 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/20
                        px-3 py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          <Icon icon="heroicons:exclamation-triangle" aria-hidden="true"
                className="h-4 w-4 shrink-0 text-healthcare-warning dark:text-healthcare-warning-dark" />
          <span className="min-w-0">
            Live updates interrupted — showing last good data from {updatedLabel}.{' '}
            <button type="button" onClick={onRefresh}
                    className="font-medium underline underline-offset-2 hover:no-underline">
              Retry now
            </button>
          </span>
        </div>
      )}

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
