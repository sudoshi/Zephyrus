// resources/js/Components/CommandCenter/OkrScoreboard.tsx
import type { Objective } from '@/types/commandCenter';
import { STATUS_VAR } from './status';

export function OkrScoreboard({ objectives }: { objectives: Objective[] }) {
  return (
    <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
      {objectives.map((o) => (
        <div key={o.key} className="flex flex-col gap-2 rounded-lg p-3"
             style={{ background: 'var(--surface-overlay)' }}>
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{o.title}</h3>
          <ul className="flex flex-col gap-2">
            {o.keyResults.map((kr) => {
              const pct = Math.max(0, Math.min(100, kr.progressPct));
              return (
                <li key={kr.label} className="flex flex-col gap-1">
                  <div className="flex items-center justify-between text-xs">
                    <span style={{ color: 'var(--text-secondary)' }}>{kr.label}</span>
                    <span className="tabular-nums" style={{ color: STATUS_VAR[kr.status] }}>{kr.display}</span>
                  </div>
                  <div className="h-1.5 w-full rounded-full" style={{ background: 'var(--surface-raised)' }}>
                    <div data-testid={`kr-progress-${kr.label}`} className="h-full rounded-full"
                         style={{ width: `${pct}%`, background: STATUS_VAR[kr.status] }} />
                  </div>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </div>
  );
}
