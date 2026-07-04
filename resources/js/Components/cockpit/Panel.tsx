// resources/js/Components/cockpit/Panel.tsx
//
// Drill-entry panel wrapper (Zephyrus 2.0 P0) — DISTINCT from the bare
// CommandCenter/Panel re-export of Surface. The header row is the drill
// affordance: when onDrill is set it becomes a focusable <button> (gold
// :focus-visible from the global focus styles, aria-haspopup="dialog") with a
// ⤢ expand glyph, per the cockpit spec "every panel header is a drill-down
// entry point". Body delegates to the ONE Surface primitive.
import type { ReactNode } from 'react';
import type { StatusLevel } from '@/types/commandCenter';
import { Surface } from '@/Components/ui/Surface';
import { statusStyle } from './statusStyle';

export interface PanelProps {
  title: string;
  /** Optional status accent — renders the state glyph beside the title. */
  accent?: StatusLevel;
  timestamp?: string;
  /** When set, the header becomes the drill-open button. */
  onDrill?: () => void;
  children: ReactNode;
  className?: string;
}

export function Panel({ title, accent, timestamp, onDrill, children, className = '' }: PanelProps) {
  const accentStyle = accent ? statusStyle(accent) : null;
  const header = (
    <div className="flex w-full items-center justify-between gap-2">
      <span className="flex min-w-0 items-center gap-1.5">
        {accentStyle && (
          <span role="img" aria-label={accentStyle.label} className="text-xs leading-none" style={{ color: accentStyle.color }}>
            {accentStyle.glyph}
          </span>
        )}
        <span className="min-w-0 truncate text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {title}
        </span>
      </span>
      <span className="flex shrink-0 items-center gap-2">
        {timestamp && (
          <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {timestamp}
          </span>
        )}
        {onDrill && (
          <span aria-hidden="true" className="text-sm leading-none text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {'⤢'}
          </span>
        )}
      </span>
    </div>
  );

  return (
    <Surface className={`flex flex-col gap-2 p-4 ${className}`}>
      {onDrill ? (
        <button
          type="button"
          onClick={onDrill}
          aria-haspopup="dialog"
          aria-label={`Open ${title} drill-down`}
          className="-m-1 rounded p-1 text-left transition-colors hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
        >
          {header}
        </button>
      ) : (
        header
      )}
      {children}
    </Surface>
  );
}
