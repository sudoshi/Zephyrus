// resources/js/Components/CommandCenter/HeroWall.tsx
import type { KpiMetric, Objective, StrainState } from '@/types/commandCenter';
import type { CommandRole } from '@/stores/commandCenterStore';
import { StrainIndex } from './StrainIndex';
import { KpiTile } from './KpiTile';
import { OkrScoreboard } from './OkrScoreboard';

interface HeroWallProps {
  role: CommandRole;
  strain: StrainState;
  heroMetrics: KpiMetric[];
  objectives: Objective[];
  detailed?: boolean;
}

export function HeroWall({ role, strain, heroMetrics, objectives, detailed = false }: HeroWallProps) {
  if (role === 'executive') {
    return (
      <div aria-label="OKR scoreboard">
        <OkrScoreboard objectives={objectives} />
      </div>
    );
  }

  return (
    <div className="grid gap-2"
         style={{ gridTemplateColumns: 'minmax(240px, 1.3fr) repeat(auto-fit, minmax(168px, 1fr))' }}>
      <StrainIndex strain={strain} />
      {heroMetrics.map((m) => <KpiTile key={m.key} metric={m} detailed={detailed} />)}
    </div>
  );
}
