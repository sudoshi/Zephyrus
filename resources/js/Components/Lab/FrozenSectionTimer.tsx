import { Link } from '@inertiajs/react';
import { Microscope } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FrozenSectionTimerContract } from '@/features/lab/schemas';

export default function FrozenSectionTimer({ timer }: { timer: FrozenSectionTimerContract | null | undefined }) {
  const mountedAt = useRef(Date.now());
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const interval = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => window.clearInterval(interval);
  }, []);

  if (!timer) return null;
  const elapsed = timer.elapsedMinutes + Math.max(0, Math.floor((now - mountedAt.current) / 60_000));

  return <Link href={timer.drillHref} aria-label={`Frozen section active for ${elapsed} minutes. ${timer.explanation}`} className="inline-flex max-w-full items-center gap-1.5 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 px-2 py-1 text-xs font-medium text-healthcare-warning dark:text-healthcare-warning-dark">
    <Microscope className="size-3.5 shrink-0" aria-hidden="true" />
    <span className="truncate">Frozen section · <span role="timer" aria-live="off" className="tabular-nums">{elapsed} min</span></span>
  </Link>;
}
