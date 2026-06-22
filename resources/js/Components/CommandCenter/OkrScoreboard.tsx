// resources/js/Components/CommandCenter/OkrScoreboard.tsx
import type { Objective } from '@/types/commandCenter';
import { STATUS_VAR } from './status';
import { Panel } from './Panel';

export function OkrScoreboard({ objectives }: { objectives: Objective[] }) {
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
                  <div className="h-1.5 w-full rounded-full bg-healthcare-border dark:bg-healthcare-border-dark">
                    <div data-testid={`kr-progress-${kr.label}`} className="h-full rounded-full"
                         style={{ width: `${pct}%`, background: STATUS_VAR[kr.status] }} />
                  </div>
                </li>
              );
            })}
          </ul>
        </Panel>
      ))}
    </div>
  );
}
