// resources/js/Components/CommandCenter/StrainIndex.tsx
import type { StrainState } from '@/types/commandCenter';
import { STATUS_VAR } from './status';
import { Panel } from './Panel';
import { Gauge } from './Gauge';

const MAX_SURGE_LEVEL = 4;

export function StrainIndex({ strain }: { strain: StrainState }) {
  const color = STATUS_VAR[strain.status];
  const trend = strain.level > strain.previousLevel ? '▲'
    : strain.level < strain.previousLevel ? '▼' : '▬';

  return (
    <Panel role="status" aria-label={`${strain.label}, status ${strain.status}`}
           className="flex h-full flex-col gap-3 p-4"
           style={{ border: `1px solid ${color}` }}>
      <span className="text-xs uppercase tracking-widest text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        House Status
      </span>

      <div className="flex items-center gap-4">
        <Gauge value={strain.level} max={MAX_SURGE_LEVEL} color={color}
               size={96} strokeWidth={10}
               centerLabel={String(strain.level)} centerLabelClass="text-3xl" centerSubLabel="Surge" />
        <div className="flex flex-col">
          <span className="text-lg font-semibold leading-tight" style={{ color }}>{strain.label}</span>
          <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true">
            {trend} from L{strain.previousLevel}
          </span>
        </div>
      </div>

      <ul className="mt-auto flex flex-col gap-1 border-t border-healthcare-border dark:border-healthcare-border-dark pt-2">
        {strain.drivers.map((d) => (
          <li key={d.label} className="flex items-center justify-between text-xs">
            <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{d.label}</span>
            <span className="tabular-nums font-medium" style={{ color: STATUS_VAR[d.status] }}>{d.value}</span>
          </li>
        ))}
      </ul>
    </Panel>
  );
}
