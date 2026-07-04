// resources/js/Components/cockpit/StatusChip.tsx
//
// The load-bearing accessibility primitive (Zephyrus 2.0 P0): shape + color +
// label together, so status is NEVER encoded by color alone (WCAG 1.4.1). The
// glyph differs per tier (– ● ▲ ◆), so meaning survives grayscale, color-
// blindness, and a wall display read across the room.
import type { StatusLevel } from '@/types/commandCenter';
import { statusStyle } from './statusStyle';

export interface StatusChipProps {
  status: StatusLevel;
  /** Optional visible text beside the glyph (the aria-label is always set). */
  label?: string;
  size?: 'sm' | 'md';
}

export function StatusChip({ status, label, size = 'sm' }: StatusChipProps) {
  const s = statusStyle(status);
  const textClass = size === 'sm' ? 'text-xs' : 'text-sm';
  return (
    <span className="inline-flex items-center gap-1" data-testid={`status-chip-${status}`}>
      <span role="img" aria-label={s.label} className={`${textClass} leading-none`} style={{ color: s.color }}>
        {s.glyph}
      </span>
      {label && (
        <span className={`${textClass} text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}>
          {label}
        </span>
      )}
    </span>
  );
}
