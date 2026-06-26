// resources/js/Components/ui/Surface.tsx
//
// THE single canonical surface primitive for Zephyrus. Every card / panel in
// the app must resolve to this treatment so there is exactly ONE definition of
// "a surface": healthcare-surface background, healthcare-border, soft elevation
// that lifts on hover, a smooth light/dark transition, and a subtle top-down
// "3D" sheen. Pass padding (p-2 / p-3 / p-4) and any accent border via
// className / style.
//
// Consumers (do not re-implement this treatment elsewhere):
//   - Components/Dashboard/Card.jsx        (Card root)
//   - Components/CommandCenter/Panel.tsx   (re-exports Surface as Panel)
//   - Components/ui/Panel.jsx              (title/header convenience wrapper)
//   - Components/ui/MetricCard.jsx         (compact metric tile)
import { forwardRef, type HTMLAttributes } from 'react';
import { useDarkMode } from '@/hooks/useDarkMode';

// Gold-standard sheen — a soft top-down highlight layered over the surface
// color. Light mode gets a brighter sheen; dark mode a faint one.
const SHEEN_LIGHT = 'linear-gradient(to bottom, rgba(255,255,255,0.55), rgba(255,255,255,0))';
const SHEEN_DARK = 'linear-gradient(to bottom, rgba(255,255,255,0.10), transparent)';

export const Surface = forwardRef<HTMLDivElement, HTMLAttributes<HTMLDivElement>>(
  function Surface({ className = '', style, children, ...rest }, ref) {
    const [isDarkMode] = useDarkMode();
    return (
      <div
        ref={ref}
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
  },
);
