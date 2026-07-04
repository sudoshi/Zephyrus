// resources/js/Pages/Dashboard/CommandCenter.tsx
//
// Zephyrus 2.0 P2 — /dashboard is the ONE cockpit home. The server sends the
// single cockpit snapshot (legacy contract + §3.2 sections) as the Inertia
// initial render; TanStack Query then polls /api/cockpit/snapshot (ETag/304)
// so refreshes never re-run the Inertia page cycle. Parsing is defensive and
// layered: sections parse → cockpit grammar; legacy parse only → classic
// four-band view (the rollback path, also forced by ?cockpit=0 or
// COCKPIT_OVERVIEW_ENABLED=false); neither → error card. Never white-screen.
//
// D2 deep links (load-bearing for P4a's redirects): ?drill={domain} is read on
// mount, held in state, kept in sync with pushState/popstate so Back works —
// P3 auto-opens the matching DrillModal from this state. ?display=wall is
// wired through to the overview (P8 builds full wall mode on it).
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import ErrorBoundary from '@/Components/ErrorBoundary';
import { safeParseCommandCenterData } from '@/types/commandCenter';
import {
  isCockpitDrillDomain,
  safeParseCockpitSections,
  type CockpitDrillDomain,
} from '@/types/cockpit';
import { COCKPIT_REFRESH_MS, useCockpitSnapshot } from '@/features/cockpit/hooks';
import { CommandCenterView } from '@/Components/CommandCenter/CommandCenterView';
import { CommandCenterError, relativeTimeFrom } from '@/Components/CommandCenter/states';
import { RoleSwitcher } from '@/Components/CommandCenter/RoleSwitcher';
import { CockpitOverview } from '@/Components/cockpit/CockpitOverview';
import { useCommandCenterStore } from '@/stores/commandCenterStore';

const REFRESH_MS = COCKPIT_REFRESH_MS;
// Two missed refreshes (plus latency headroom) ⇒ the payload is stale. Driven
// off the data's own timestamp so it catches every failure mode — network
// error, hung server, suspended tab — not just a caught request rejection.
const STALE_MS = REFRESH_MS * 2.5;
// One overdue refresh ⇒ "aging": a quiet amber cue before the loud stale banner.
const AGING_MS = REFRESH_MS * 1.4;
const TICK_MS = 15_000;

function urlParam(name: string): string | null {
  if (typeof window === 'undefined') return null;
  return new URLSearchParams(window.location.search).get(name);
}

function drillFromUrl(): CockpitDrillDomain | null {
  const param = urlParam('drill');
  return isCockpitDrillDomain(param) ? param : null;
}

/** Seed TanStack's freshness clock from the payload's own timestamp. */
function payloadTimestampMs(input: unknown): number | undefined {
  if (typeof input !== 'object' || input === null) return undefined;
  const record = input as Record<string, unknown>;
  const iso = typeof record.asOf === 'string'
    ? record.asOf
    : typeof record.generatedAtIso === 'string' ? record.generatedAtIso : null;
  if (iso === null) return undefined;
  const ms = Date.parse(iso);
  return Number.isNaN(ms) ? undefined : ms;
}

export default function CommandCenter({
  data,
  cockpitEnabled = false,
}: {
  data: unknown;
  cockpitEnabled?: boolean;
}) {
  const query = useCockpitSnapshot(data, payloadTimestampMs(data));
  const raw = query.data ?? data;

  const parsed = useMemo(() => safeParseCommandCenterData(raw), [raw]);
  const sections = useMemo(() => safeParseCockpitSections(raw), [raw]);

  const role = useCommandCenterStore((s) => s.role);
  const [nowMs, setNowMs] = useState(() => Date.now());

  // Read-once presentation params (D2). ?cockpit=1 forces the new grammar on,
  // ?cockpit=0 forces the classic rollback view regardless of the config flag.
  const [cockpitParam] = useState(() => urlParam('cockpit'));
  const [wall] = useState(() => urlParam('display') === 'wall');
  const [drill, setDrill] = useState<CockpitDrillDomain | null>(drillFromUrl);

  // Drill state ↔ URL: pushState on change so drills are shareable and the
  // browser Back button walks drill history (popstate syncs state back).
  const handleDrillChange = useCallback((domain: CockpitDrillDomain | null) => {
    setDrill(domain);
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (domain) url.searchParams.set('drill', domain);
    else url.searchParams.delete('drill');
    window.history.pushState(window.history.state, '', url);
  }, []);

  useEffect(() => {
    const onPopState = () => setDrill(drillFromUrl());
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  // Tick a clock so the freshness label and stale detection stay truthful even
  // when no fresh payload arrives. A silently failed refresh must surface.
  useEffect(() => {
    const id = setInterval(() => setNowMs(Date.now()), TICK_MS);
    return () => clearInterval(id);
  }, []);

  const handleRefresh = useCallback(() => {
    void query.refetch();
  }, [query]);

  const cockpitActive =
    sections.ok && (cockpitParam === '1' || (cockpitParam !== '0' && cockpitEnabled));

  const generatedIso = sections.ok
    ? sections.data.asOf
    : parsed.ok ? parsed.data.generatedAtIso : null;
  const ageMs = generatedIso !== null ? nowMs - Date.parse(generatedIso) : 0;
  const stale = ageMs > STALE_MS;
  const aging = !stale && ageMs > AGING_MS;
  const updatedLabel = generatedIso !== null ? relativeTimeFrom(generatedIso, nowMs) : '—';
  const refreshing = query.isFetching;

  return (
    <DashboardLayout>
      <Head title="Operations Command Center · Zephyrus" />
      <PageContentLayout
        title="Hospital Operations Command Center"
        subtitle="House-wide demand, capacity, flow & forecast"
        headerContent={<RoleSwitcher />}
      >
        {cockpitActive ? (
          <ErrorBoundary
            fallback={(error?: Error) => (
              <CommandCenterError detail={error?.message} onRetry={handleRefresh} />
            )}
          >
            <CockpitOverview
              sections={sections.data}
              role={role}
              updatedLabel={updatedLabel}
              refreshing={refreshing}
              aging={aging}
              stale={stale}
              onRefresh={handleRefresh}
              activeDrill={drill}
              onDrillChange={handleDrillChange}
              wall={wall}
            />
          </ErrorBoundary>
        ) : parsed.ok ? (
          <ErrorBoundary
            fallback={(error?: Error) => (
              <CommandCenterError detail={error?.message} onRetry={handleRefresh} />
            )}
          >
            <CommandCenterView
              data={parsed.data}
              onRefresh={handleRefresh}
              refreshing={refreshing}
              updatedLabel={updatedLabel}
              aging={aging}
              stale={stale}
            />
          </ErrorBoundary>
        ) : (
          <CommandCenterError
            detail={sections.ok ? parsed.error : sections.error}
            onRetry={handleRefresh}
          />
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
}
