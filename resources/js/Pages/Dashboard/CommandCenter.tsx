// resources/js/Pages/Dashboard/CommandCenter.tsx
import { useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import ErrorBoundary from '@/Components/ErrorBoundary';
import { safeParseCommandCenterData } from '@/types/commandCenter';
import { CommandCenterView } from '@/Components/CommandCenter/CommandCenterView';
import { CommandCenterError, relativeTimeFrom } from '@/Components/CommandCenter/states';
import { RoleSwitcher } from '@/Components/CommandCenter/RoleSwitcher';

const REFRESH_MS = 45_000;
// Two missed refreshes (plus latency headroom) ⇒ the payload is stale. Driven
// off the data's own timestamp so it catches every failure mode — network
// error, hung server, suspended tab — not just a caught request rejection.
const STALE_MS = REFRESH_MS * 2.5;
// One overdue refresh ⇒ "aging": a quiet amber cue before the loud stale banner.
const AGING_MS = REFRESH_MS * 1.4;
const TICK_MS = 15_000;

export default function CommandCenter({ data }: { data: unknown }) {
  const parsed = useMemo(() => safeParseCommandCenterData(data), [data]);
  const [nowMs, setNowMs] = useState(() => Date.now());
  const [refreshing, setRefreshing] = useState(false);

  // Periodic background refresh of the payload only.
  useEffect(() => {
    const id = setInterval(() => {
      setRefreshing(true);
      router.reload({ only: ['data'], onFinish: () => setRefreshing(false) });
    }, REFRESH_MS);
    return () => clearInterval(id);
  }, []);

  // Tick a clock so the freshness label and stale detection stay truthful even
  // when no fresh payload arrives. A silently failed refresh must surface.
  useEffect(() => {
    const id = setInterval(() => setNowMs(Date.now()), TICK_MS);
    return () => clearInterval(id);
  }, []);

  const handleRefresh = () => {
    setRefreshing(true);
    router.reload({ only: ['data'], onFinish: () => setRefreshing(false) });
  };

  const ageMs = parsed.ok ? nowMs - Date.parse(parsed.data.generatedAtIso) : 0;
  const stale = ageMs > STALE_MS;
  const aging = !stale && ageMs > AGING_MS;

  return (
    <DashboardLayout>
      <Head title="Operations Command Center · Zephyrus" />
      <PageContentLayout
        title="Hospital Operations Command Center"
        subtitle="House-wide demand, capacity, flow & forecast"
        headerContent={<RoleSwitcher />}
      >
        {parsed.ok ? (
          <ErrorBoundary
            fallback={(error?: Error) => (
              <CommandCenterError detail={error?.message} onRetry={handleRefresh} />
            )}
          >
            <CommandCenterView
              data={parsed.data}
              onRefresh={handleRefresh}
              refreshing={refreshing}
              updatedLabel={relativeTimeFrom(parsed.data.generatedAtIso, nowMs)}
              aging={aging}
              stale={stale}
            />
          </ErrorBoundary>
        ) : (
          <CommandCenterError detail={parsed.error} onRetry={handleRefresh} />
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
}
