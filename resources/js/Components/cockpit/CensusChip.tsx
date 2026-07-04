// resources/js/Components/cockpit/CensusChip.tsx
//
// Larger-value census tile for the cockpit's 8-chip CensusStrip (Zephyrus 2.0
// P0). Applies the ISA-101 grey-baseline rule from day one: the value renders
// near-white unless the state has EARNED color (ok rationed / warn / crit) —
// statusStyle.valuePrimary is the single policy source. UnitHeatStrip cards
// become a CensusChip variant in P2.
import type { StatusLevel } from '@/types/commandCenter';
import { Surface } from '@/Components/ui/Surface';
import { statusStyle } from './statusStyle';

export interface CensusChipProps {
  label: string;
  value: string | number;
  sub?: string;
  status: StatusLevel;
}

export function CensusChip({ label, value, sub, status }: CensusChipProps) {
  const s = statusStyle(status);
  return (
    <Surface className="flex flex-col gap-1 p-3" data-testid={`census-chip-${label}`}>
      <div className="flex items-start justify-between gap-2">
        <span className="min-w-0 truncate text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {label}
        </span>
        <span role="img" aria-label={s.label} className="text-xs leading-none" style={{ color: s.color }}>
          {s.glyph}
        </span>
      </div>
      <span
        className={`text-2xl font-semibold tabular-nums leading-none ${
          s.valuePrimary ? 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark' : ''
        }`}
        style={s.valuePrimary ? undefined : { color: s.color }}
      >
        {value}
      </span>
      {sub && (
        <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {sub}
        </span>
      )}
    </Surface>
  );
}
