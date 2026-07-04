// resources/js/Components/CommandCenter/OkrScoreboard.tsx
import type { Objective } from '@/types/commandCenter';
import { STATUS_VAR } from './status';
import { Panel } from './Panel';
import { EmptyState } from './states';
import { MeterBar } from '@/Components/cockpit/MeterBar';

export function OkrScoreboard({ objectives }: { objectives: Objective[] }) {
  if (objectives.length === 0) {
    return <EmptyState message="No objectives configured for this period" icon="heroicons:flag" />;
  }
  return (
    <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
      {objectives.map((o) => (
        <Panel key={o.key} className="flex flex-col gap-2 p-4">
          <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{o.title}</h3>
          <ul className="flex flex-col gap-2">
            {o.keyResults.map((kr) => {
              const pct = Math.max(0, Math.min(100, kr.progressPct));
              return (
                <li key={kr.label} className="flex flex-col gap-1">
                  <div className="flex items-center justify-between text-xs">
                    <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{kr.label}</span>
                    <span className="tabular-nums" style={{ color: STATUS_VAR[kr.status] }}>{kr.display}</span>
                  </div>
                  <MeterBar pct={pct} status={kr.status} testId={`kr-progress-${kr.label}`} />
                </li>
              );
            })}
          </ul>
        </Panel>
      ))}
    </div>
  );
}
