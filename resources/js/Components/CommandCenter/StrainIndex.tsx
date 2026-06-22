// resources/js/Components/CommandCenter/StrainIndex.tsx
import type { StrainState } from '@/types/commandCenter';
import { STATUS_VAR } from './status';

export function StrainIndex({ strain }: { strain: StrainState }) {
  const color = STATUS_VAR[strain.status];
  const trend = strain.level > strain.previousLevel ? '▲'
    : strain.level < strain.previousLevel ? '▼' : '▬';

  return (
    <div role="status" aria-label={`${strain.label}, status ${strain.status}`}
         className="flex h-full flex-col gap-2 rounded-lg p-4"
         style={{ background: 'var(--surface-overlay)', border: `1px solid ${color}` }}>
      <span className="text-xs uppercase tracking-widest" style={{ color: 'var(--text-muted)' }}>
        House Status
      </span>
      <div className="flex items-baseline gap-2">
        <span className="text-4xl font-semibold leading-none" style={{ color }}>{strain.label}</span>
        <span className="text-sm" style={{ color }} aria-hidden="true">
          {trend} from L{strain.previousLevel}
        </span>
      </div>
      <ul className="mt-1 flex flex-col gap-1">
        {strain.drivers.map((d) => (
          <li key={d.label} className="flex items-center justify-between text-xs">
            <span style={{ color: 'var(--text-secondary)' }}>{d.label}</span>
            <span className="tabular-nums" style={{ color: STATUS_VAR[d.status] }}>{d.value}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
