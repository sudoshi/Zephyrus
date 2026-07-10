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
// the DrillModal (P3) auto-opens from this state, and ESC/backdrop/Back all
// close it through the same handler. ?display=wall switches the same live
// surface into its static, chromeless presentation: interaction deep links are
// stripped and no drill, patient, inbox, or assistant surface is mounted.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import ErrorBoundary from '@/Components/ErrorBoundary';
import { safeParseCommandCenterData } from '@/types/commandCenter';
import {
  isCockpitDrillDomain,
  isScopedMount,
  safeParseCockpitSections,
  type CockpitAlert,
  type CockpitDrillDomain,
} from '@/types/cockpit';
import { isPatientContextRef } from '@/types/patientLens';
import { useEddyStore } from '@/stores/eddyStore';
import { COCKPIT_REFRESH_MS, useCockpitFace, useCockpitSnapshot } from '@/features/cockpit/hooks';
import { useLiveCockpit } from '@/features/cockpit/live';
import { CommandCenterView } from '@/Components/CommandCenter/CommandCenterView';
import { CommandCenterError, relativeTimeFrom } from '@/Components/CommandCenter/states';
import { CockpitOverview } from '@/Components/cockpit/CockpitOverview';
import { ScopedFaceView } from '@/Components/cockpit/ScopedFaceView';
import { ScopePicker } from '@/Components/cockpit/ScopePicker';
import { StaleDataBanner } from '@/Components/cockpit/StaleDataBanner';
import { useCockpitStream } from '@/features/cockpit/useCockpitStream';
import { DrillModal } from '@/Components/cockpit/DrillModal';
import { PatientLensModal } from '@/Components/cockpit/PatientLensModal';
import { ActionInboxModal } from '@/Components/cockpit/ActionInboxModal';
import { ExecutiveBriefPanel } from '@/Components/cockpit/ExecutiveBriefPanel';
import { useAgentInbox } from '@/features/ops/hooks';
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

// P8 WS-3: ?patient={ptok} opens the A2P patient lens OVER the current mount.
// Like ?drill=, it is dynamic (pushState/popstate-synced) so a bed/board drill
// (wired in WS-4) is shareable and Back-navigable; a non-ptok value is ignored.
function patientFromUrl(): string | null {
  const param = urlParam('patient');
  return isPatientContextRef(param) ? param : null;
}

/** Remove desk-only interaction state without disturbing the mounted scope. */
function stripWallInteractionParams(): void {
  if (typeof window === 'undefined') return;
  const url = new URL(window.location.href);
  const hasInteractionState = url.searchParams.has('drill') || url.searchParams.has('patient');
  url.searchParams.delete('drill');
  url.searchParams.delete('patient');
  if (hasInteractionState) {
    window.history.replaceState(window.history.state, '', url);
  }
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
  // P6 WS-7: Reverb reload ping — a fresh snapshot invalidates the poll cache
  // so the wall updates within seconds instead of at the next 45s tick.
  useLiveCockpit();
  // P8 WS-6b: the SSE safety-floor path — keeps the cockpit live even when Reverb
  // is down (BROADCAST_CONNECTION=null in prod). The 45s poll remains the fallback.
  useCockpitStream();
  const raw = query.data ?? data;

  const parsed = useMemo(() => safeParseCommandCenterData(raw), [raw]);
  const sections = useMemo(() => safeParseCockpitSections(raw), [raw]);

  const role = useCommandCenterStore((s) => s.role);
  const [nowMs, setNowMs] = useState(() => Date.now());

  // Read-once presentation params (D2). ?cockpit=1 forces the new grammar on,
  // ?cockpit=0 forces the classic rollback view regardless of the config flag.
  const [cockpitParam] = useState(() => urlParam('cockpit'));
  const [wall] = useState(() => urlParam('display') === 'wall');
  // P8 WS-2b: ?scope= mounts a non-house altitude (unit / department / service
  // line). Read once like the other presentation params; an absent or 'house'
  // token keeps the default house overview (no scoped fetch, no behavior change).
  const [scopeToken] = useState(() => urlParam('scope'));
  // P8 WS-6b: a scoped mount's visible surface is the face (fetched independently,
  // deduped with ScopedFaceView), so the stale banner must track the FACE's own
  // freshness — not the house snapshot's. null off a scoped mount ⇒ no fetch.
  const scopedToken = isScopedMount(scopeToken) ? scopeToken : null;
  const faceQuery = useCockpitFace(scopedToken);
  const [drill, setDrill] = useState<CockpitDrillDomain | null>(() => wall ? null : drillFromUrl());
  const [patient, setPatient] = useState<string | null>(() => wall ? null : patientFromUrl());

  // Drill state ↔ URL: pushState on change so drills are shareable and the
  // browser Back button walks drill history (popstate syncs state back).
  const handleDrillChange = useCallback((domain: CockpitDrillDomain | null) => {
    if (wall) return;
    setDrill(domain);
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (domain) url.searchParams.set('drill', domain);
    else url.searchParams.delete('drill');
    window.history.pushState(window.history.state, '', url);
  }, [wall]);

  // Patient lens ↔ URL: same pushState/popstate discipline as the drill, so the
  // A2P descent is shareable and Back closes it (WS-4 sets this from a row).
  const handlePatientChange = useCallback((contextRef: string | null) => {
    if (wall) return;
    setPatient(contextRef);
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (contextRef) url.searchParams.set('patient', contextRef);
    else url.searchParams.delete('patient');
    window.history.pushState(window.history.state, '', url);
  }, [wall]);

  useEffect(() => {
    const onPopState = () => {
      if (wall) {
        setDrill(null);
        setPatient(null);
        stripWallInteractionParams();
        return;
      }
      setDrill(drillFromUrl());
      setPatient(patientFromUrl());
    };
    onPopState();
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, [wall]);

  // Tick a clock so the freshness label and stale detection stay truthful even
  // when no fresh payload arrives. A silently failed refresh must surface.
  useEffect(() => {
    const id = setInterval(() => setNowMs(Date.now()), TICK_MS);
    return () => clearInterval(id);
  }, []);

  const handleRefresh = useCallback(() => {
    void query.refetch();
    // On a scoped mount the visible surface is the face — retry that too.
    if (scopedToken !== null) void faceQuery.refetch();
  }, [query, faceQuery, scopedToken]);

  // P6 WS-4: ticker → EddyDock hand-off. The dock opens pre-seeded with the
  // alert context + its server-resolved catalog action; the operator reviews
  // and sends (advice, not autopilot). Only wired when the dock exists.
  const eddyEnabled = Boolean(
    (usePage().props as { eddy?: { enabled?: boolean } }).eddy?.enabled,
  );
  const openWithPrefill = useEddyStore((s) => s.openWithPrefill);
  const handleAlertEngage = useCallback(
    (alert: CockpitAlert) => {
      const ask = alert.actionLabel
        ? `Draft a "${alert.actionLabel}" proposal for my review.`
        : 'What is the recommended action?';
      openWithPrefill(
        `Cockpit alert ${alert.key} (${alert.status.toUpperCase()}): ${alert.text}. ${ask}`,
        alert.key,
      );
    },
    [openWithPrefill],
  );

  // P6 WS-5: the governed action queue in the cockpit — one fetch per mount
  // (no polling; the useDecideApproval mutation invalidates on decisions).
  const inbox = useAgentInbox(!wall);
  const [inboxOpen, setInboxOpen] = useState(false);
  const pendingApprovals = inbox.data?.summary.pendingApprovals ?? 0;

  const cockpitActive =
    sections.ok && (cockpitParam === '1' || (cockpitParam !== '0' && cockpitEnabled));
  // A non-house mount renders the scoped altitude face instead of the house
  // grid; house (or no ?scope=) is the untouched overview path.
  const scopedMount = cockpitActive && scopedToken !== null;

  const snapshotIso = sections.ok
    ? sections.data.asOf
    : parsed.ok ? parsed.data.generatedAtIso : null;
  // The visible surface's last-known-good instant drives freshness: the scoped
  // face's successful-fetch time on a ?scope= mount, else the house snapshot's own
  // server timestamp — so a stalled face over a fresh snapshot still fires the banner.
  const freshMs = scopedToken !== null
    ? (faceQuery.dataUpdatedAt > 0 ? faceQuery.dataUpdatedAt : NaN)
    : snapshotIso !== null ? Date.parse(snapshotIso) : NaN;
  const ageMs = Number.isNaN(freshMs) ? 0 : nowMs - freshMs;
  const stale = ageMs > STALE_MS;
  const aging = !stale && ageMs > AGING_MS;
  const updatedLabel = Number.isNaN(freshMs)
    ? '—'
    : relativeTimeFrom(new Date(freshMs).toISOString(), nowMs);
  const refreshing = query.isFetching || (scopedToken !== null && faceQuery.isFetching);

  // Recovery announcement, app-chrome-wide (P8 WS-6b): the loud StaleDataBanner
  // announces stale ONSET on every mount but on recovery just un-renders; announce
  // the stale → fresh transition here so screen-reader users on the cockpit + the
  // scoped face hear it (previously only the classic rollback view did).
  const wasStale = useRef(stale);
  const [recoveryNote, setRecoveryNote] = useState('');
  useEffect(() => {
    if (wasStale.current && !stale) setRecoveryNote('Live updates resumed. Data is current.');
    wasStale.current = stale;
  }, [stale]);

  return (
    <DashboardLayout wall={wall}>
      <Head title="Operations Command Center · Zephyrus" />
      {/* P4a: the RoleSwitcher moved to persistent app chrome (TopNavbar) —
          it is no longer page-local header content. */}
      {/* Keep the mount selector in the command-center header so House, Units,
          Departments, and Service Lines remain available without consuming a
          separate content row. A wall stays pinned to its configured scope. */}
      <PageContentLayout
        title="Hospital Operations Command Center"
        subtitle="House-wide demand, capacity, flow & forecast"
        headerContent={cockpitActive && !wall ? <ScopePicker activeToken={scopeToken} /> : null}
      >
        {/* P8 WS-6b: recovery announcement (stale → fresh) for SR users, app-wide.
            The banner announces onset; this announces the recovery it can't. */}
        <div className="sr-only" role="status" aria-live="polite" aria-label="Live update status">
          {recoveryNote}
        </div>
        {/* P8 WS-6b: the stale banner is app-chrome-wide so it fires at EVERY
            mount (house, scoped face, or wall) — never a silent stale screen.
            Self-hides when the data is fresh. */}
        <StaleDataBanner
          stale={stale}
          updatedLabel={updatedLabel}
          onRetry={wall ? undefined : handleRefresh}
          className="mb-3"
        />
        {cockpitActive ? (
          <ErrorBoundary
            fallback={(error?: Error) => (
              <CommandCenterError detail={error?.message} onRetry={wall ? undefined : handleRefresh} />
            )}
          >
            {scopedMount ? (
              // P8 WS-2b: a non-house mount — the scope's own altitude face,
              // fetched from /api/cockpit/face and rendered with the same
              // Tile / DataTable primitives the house grid and drills use.
              <ScopedFaceView
                scopeToken={scopeToken as string}
                interactive={!wall}
                onPatientDrill={wall ? undefined : handlePatientChange}
              />
            ) : (
              <>
                <CockpitOverview
                  sections={sections.data}
                  role={role}
                  updatedLabel={updatedLabel}
                  refreshing={refreshing}
                  aging={aging}
                  stale={stale}
                  onRefresh={handleRefresh}
                  activeDrill={wall ? null : drill}
                  onDrillChange={handleDrillChange}
                  wall={wall}
                  onAlertEngage={!wall && eddyEnabled ? handleAlertEngage : undefined}
                  onOpenInbox={!wall ? () => setInboxOpen(true) : undefined}
                  inboxCount={pendingApprovals}
                  briefPanel={!wall && role === 'executive' ? <ExecutiveBriefPanel /> : undefined}
                />
                {/* A2 drill (P3): opens from panel/OKR headers AND from ?drill=
                    deep links; closing (ESC, backdrop, ×) clears the URL param. */}
                {!wall && (
                  <DrillModal domain={drill} onClose={() => handleDrillChange(null)} onPatientDrill={handlePatientChange} />
                )}
                {/* P6 WS-5: the AgentInbox queue as an in-cockpit modal. */}
                {!wall && <ActionInboxModal open={inboxOpen} onClose={() => setInboxOpen(false)} />}
              </>
            )}
            {/* P8 WS-3: the A2P patient lens overlays ANY mount (house or
                scoped) — ?patient={ptok} opens it; WS-4 wires bed/board rows. */}
            {!wall && <PatientLensModal contextRef={patient} onClose={() => handlePatientChange(null)} />}
          </ErrorBoundary>
        ) : parsed.ok ? (
          <ErrorBoundary
            fallback={(error?: Error) => (
              <CommandCenterError detail={error?.message} onRetry={wall ? undefined : handleRefresh} />
            )}
          >
            <CommandCenterView
              data={parsed.data}
              onRefresh={wall ? undefined : handleRefresh}
              refreshing={refreshing}
              updatedLabel={updatedLabel}
              aging={aging}
              stale={stale}
              interactive={!wall}
            />
          </ErrorBoundary>
        ) : (
          <CommandCenterError
            detail={sections.ok ? parsed.error : sections.error}
            onRetry={wall ? undefined : handleRefresh}
          />
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
}
