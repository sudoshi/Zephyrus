// resources/js/Components/CommandCenter/Panel.tsx
import type { HTMLAttributes } from 'react';
import { useDarkMode } from '@/hooks/useDarkMode';

// Gold-standard "3D" sheen — a soft top-down highlight layered over the
// surface color, identical in spirit to Components/Dashboard/Card.jsx. Light
// mode gets a brighter sheen; dark mode a faint one.
const SHEEN_LIGHT = 'linear-gradient(to bottom, rgba(255,255,255,0.55), rgba(255,255,255,0))';
const SHEEN_DARK = 'linear-gradient(to bottom, rgba(255,255,255,0.10), transparent)';

/**
 * Surface wrapper that gives Command Center panels the gold-standard card
 * treatment: healthcare-surface background, border, soft elevation that lifts
 * on hover, smooth light/dark transition and a subtle 3D sheen. Pass padding
 * (p-3 / p-4 / p-2) and any accent border via className / style.
 */
export function Panel({ className = '', style, children, ...rest }: HTMLAttributes<HTMLDivElement>) {
  const [isDarkMode] = useDarkMode();
  return (
    <div
      {...rest}
      className={[
        'relative rounded-lg overflow-hidden',
        'bg-healthcare-surface dark:bg-healthcare-surface-dark',
        'border border-healthcare-border dark:border-healthcare-border-dark',
        'shadow-sm hover:shadow-md dark:shadow-none dark:hover:shadow-[0_4px_12px_rgba(0,0,0,0.25)]',
        'transition-all duration-300',
        className,
      ].join(' ')}
      style={{ backgroundImage: isDarkMode ? SHEEN_DARK : SHEEN_LIGHT, ...style }}
    >
      {children}
    </div>
  );
}
