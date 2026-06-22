// resources/js/Components/CommandCenter/CommandCenterView.tsx
import type { CommandCenterData } from '@/types/commandCenter';
import { useCommandCenterStore } from '@/stores/commandCenterStore';
import { HeroWall } from './HeroWall';
import { Band } from './Band';
import { UnitHeatStrip } from './UnitHeatStrip';
import { ForecastCurve } from './ForecastCurve';
import { RoleSwitcher } from './RoleSwitcher';

interface CommandCenterViewProps {
  data: CommandCenterData;
  onRefresh: () => void;
  refreshedLabel: string;
}

export function CommandCenterView({ data, onRefresh, refreshedLabel }: CommandCenterViewProps) {
  const role = useCommandCenterStore((s) => s.role);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <RoleSwitcher />
        <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
          <span>Updated {refreshedLabel}</span>
          <button type="button" onClick={onRefresh} aria-label="Refresh data"
                  className="rounded px-2 py-1"
                  style={{ background: 'var(--surface-raised)', color: 'var(--text-secondary)' }}>
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
