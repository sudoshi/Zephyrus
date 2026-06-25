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
  refreshedLabel: string;
}

export function CommandCenterView({ data, onRefresh, refreshedLabel }: CommandCenterViewProps) {
  const role = useCommandCenterStore((s) => s.role);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex justify-end">
        <div className="flex items-center gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <span>Updated {refreshedLabel}</span>
          <button type="button" onClick={onRefresh} aria-label="Refresh data"
                  className="rounded-md border border-healthcare-border dark:border-healthcare-border-dark
                             bg-healthcare-surface dark:bg-healthcare-surface-dark
                             px-2 py-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark
                             shadow-sm hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark
                             transition-colors duration-300">
            {'⟳'} Refresh
          </button>
        </div>
      </div>

      <HeroWall role={role} strain={data.strain} heroMetrics={data.heroMetrics} objectives={data.objectives} />

      <div className="flex flex-col gap-2">
        <Band band={data.capacity} />
        <UnitHeatStrip units={data.unitCensus} />
      </div>
      <Band band={data.flow} />
      <Band band={data.outcomes} />
      <div className="flex flex-col gap-2">
        <Band band={data.forecast} />
        <ForecastCurve forecast={data.forecastDetail} />
      </div>
    </div>
  );
}
