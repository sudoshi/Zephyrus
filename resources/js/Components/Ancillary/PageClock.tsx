import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

const PageClockContext = createContext<Date | null>(null);

export interface PageClockProviderProps {
  children: ReactNode;
  initialNow?: Date;
  tickMs?: number;
}

export function PageClockProvider({ children, initialNow, tickMs = 30_000 }: PageClockProviderProps) {
  const [now, setNow] = useState(() => initialNow ?? new Date());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), tickMs);
    return () => window.clearInterval(timer);
  }, [tickMs]);

  const value = useMemo(() => now, [now]);
  return <PageClockContext.Provider value={value}>{children}</PageClockContext.Provider>;
}

export function usePageClock(): Date {
  const value = useContext(PageClockContext);
  if (value === null) {
    throw new Error('Ancillary time-aware components require one PageClockProvider at page scope.');
  }
  return value;
}
