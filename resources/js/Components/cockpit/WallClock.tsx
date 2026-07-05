// resources/js/Components/cockpit/WallClock.tsx
//
// Zephyrus 2.0 P8 WS-5 (wall preset) — a 1 Hz wall clock for the cockpit's
// wall-chrome strip. Wall-display reads happen across a room, so the date is a
// dense micro-caption (text-[11px] — the ONE sanctioned arbitrary-size
// exception, scoped to Components/cockpit/) and the time is the larger, primary
// line. `tabular-nums` keeps the digits from jittering as the seconds tick.
import { useEffect, useState } from 'react';

export interface WallClockProps {
  className?: string;
}

export function WallClock({ className }: WallClockProps) {
  // Lazy initializer — never call `new Date()` at module scope (would freeze the
  // clock to import time on the server / first render).
  const [now, setNow] = useState(() => new Date());

  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 1000);
    return () => window.clearInterval(id);
  }, []);

  return (
    <div
      role="timer"
      aria-live="off"
      className={`flex flex-col leading-tight ${className ?? ''}`}
    >
      <span className="text-[11px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })}
      </span>
      <span className="text-base font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {now.toLocaleTimeString([], { hour12: false })}
      </span>
    </div>
  );
}
