import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import {
  createPatientFlowEventSource,
  fetchCaseConformance,
  fetchPatientFlowAmbient,
  fetchPatientFlowBarriers,
  fetchPatientFlowEpoch,
  fetchPatientFlowEvents,
  fetchPatientFlowLocations,
  fetchPatientFlowOccupancy,
  fetchPatientFlowProjections,
  fetchPatientFlowSummary,
  fetchSceneConformance,
  postExceptionNote,
} from '@/features/patientFlowNavigator/api';
import { pathwayLabel, sceneChipLabel } from '@/features/patientFlowNavigator/adherence';
import { adoptEpoch } from '@/features/patientFlowNavigator/epoch';
import { eventDensityBuckets, fetchPatientJourney, journeyEventTicks } from '@/features/patientFlowNavigator/journey';
import type { JourneyAlignAnchor } from '@/features/patientFlowNavigator/journey';
import type { PatientJourney } from '@/features/patientFlowNavigator/journeySchemas';
import NavigatorJourneyDrawer from './NavigatorJourneyDrawer';
import type { DrawerAdherence } from './NavigatorJourneyDrawer';
import {
  parseTime,
  patientStatesAt,
  rebuildTracks,
} from '@/features/patientFlowNavigator/stateProjection';
import {
  LIVE_WINDOW_HALF_MS,
  prepareReplay,
  recentReplayEvents,
  replayStatus,
  windowForPreset,
} from '@/features/patientFlowNavigator/replayTimeline';
import type { WindowPreset } from '@/features/patientFlowNavigator/replayTimeline';
import { buildViewSearch, parseViewState } from '@/features/patientFlowNavigator/viewState';
import type { NavigatorViewSnapshot, ParsedViewState } from '@/features/patientFlowNavigator/viewState';
import {
  ENTITY_PROJECTION_KINDS,
  aggregatesAt,
  anchorForProjection,
  buildBarrierCells,
  buildProjectionPlacementIndex,
  confidenceOpacity,
  floorForProjection,
  ghostsAt,
} from '@/features/patientFlowNavigator/projections';
import type { ForecastAggregates } from '@/features/patientFlowNavigator/projections';
import { buildOccupancyInsights } from '@/features/patientFlowNavigator/occupancyInsights';
import { useEddyStore } from '@/stores/eddyStore';
import { fetchRoundRuns, fetchRoundScene } from '@/features/virtualRounds/api';
import { runsResponseSchema, sceneResponseSchema } from '@/features/virtualRounds/schemas';
import { buildRoundRoute, buildRoundStopCells, findOpenRun } from '@/features/virtualRounds/roundsScene';
import type { RoundStop } from '@/features/virtualRounds/roundsScene';
import type { RunSummary } from '@/features/virtualRounds/types';
import type {
  FlowLens,
  FlowPatientDots,
  FlowUnitSummary,
  NavigatorBarrier,
  OccupancyInsight,
  OccupancySummary,
  PatientFlowAmbient,
  PatientFlowEvent,
  PatientFlowFilters,
  PatientFlowLocations,
  PatientFlowSummary,
  PatientLayerState,
  PatientVisibleState,
  ProjectionItem,
} from '@/features/patientFlowNavigator/types';
import { occupancyInspectorData, patientTokenInspectorData } from '@/features/patientFlowNavigator/inspector';
import { elementLabelFor, hoverLabelFor } from '@/features/patientFlowNavigator/sceneVocabulary';
import { installSoakHook } from '@/features/patientFlowNavigator/soakHook';
import {
  mergeLayers,
  parseSavedViews,
  savedViewsKey,
  serializeSavedViews,
} from '@/features/patientFlowNavigator/savedViews';
import type { SavedView } from '@/features/patientFlowNavigator/savedViews';
import {
  INTRO_SEEN,
  introStops,
  introTourKey,
  persistIntroSeen,
  shouldAutoStartIntro,
} from '@/features/patientFlowNavigator/introTour';
import type { PageProps } from '@/types';
import type { CameraView, NavigatorScene } from './NavigatorScene';
import NavigatorActionList from './NavigatorActionList';
import NavigatorChronobar from './NavigatorChronobar';
import NavigatorFeed from './NavigatorFeed';
import NavigatorFloorRail from './NavigatorFloorRail';
import NavigatorInspector from './NavigatorInspector';
import NavigatorIntro from './NavigatorIntro';
import NavigatorLegend from './NavigatorLegend';
import NavigatorMinimap from './NavigatorMinimap';
import NavigatorSmallMultiples from './NavigatorSmallMultiples';
import NavigatorStructureNav from './NavigatorStructureNav';
import NavigatorToolbar from './NavigatorToolbar';
import { gridToWorldCells, densityGrid, heatBounds } from '@/features/patientFlowNavigator/trailHeat';
import type { LayerControl, NavigatorMetrics } from './NavigatorToolbar';
import './PatientFlowNavigator.css';
import { formatDurationMinutes } from '@/lib/duration';

/**
 * Patient Flow 4D Navigator — thin orchestrator (FLOW-WINDOW-PLAN §7.3).
 *
 * three.js lives entirely in ./NavigatorScene, loaded via dynamic import so
 * the 3D stack is its own lazy chunk. This component owns data fetching, the
 * 48h Chronobar time model, the persona lens, playback/live modes, and wires
 * the presentational pieces (Toolbar / Chronobar / Inspector / Feed).
 */

interface PatientFlowNavigatorProps {
  initialFloor?: string;
  initialCategory?: string;
  initialServiceLine?: string;
  /** Resolved persona lens (Inertia prop); null → full house view. */
  lens?: FlowLens | null;
  /** unit_id ↔ unit_code ↔ floor bridge for the projection ghost layer. */
  units?: FlowUnitSummary[];
}

const IDENTITY_KEYS = ['patient_display_id', 'patient_id', 'encounter_id'] as const;

const EMPTY_OCCUPANCY_SUMMARY: OccupancySummary = {
  active: 0,
  delayed: 0,
  watch: 0,
  transportDelays: 0,
  evsDelays: 0,
  readyToMove: 0,
  avgStayMinutes: 0,
  serviceLines: [],
  persona: {
    transport: 0,
    evs: 0,
    bedManager: 0,
    capacity: 0,
  },
  topBarriers: [],
};

export interface HandoffParams extends ParsedViewState {
  floor: string | null;
  unitRef: string | null;
  t: number | null;
  /** Rounds board → 4D deep link: fly to this round stop once placed (R-1). */
  focusStop: string | null;
  /** Cross-surface patient pivot (plan B4, PJ-3): select this patient and
   * open their journey once events load. Opaque ptok only — a raw ref in
   * the URL is dropped here, exactly like the /events filter contract. */
  patient: string | null;
}

/**
 * Mobile→web A3 handoff + E5 view-state params:
 * ?persona=&scope=&t=&focus_stop=&patient=&cam=&layers=&census=&win=&sel=&wall=
 * (persona is resolved server-side). Exported for tests.
 */
export function parseHandoff(): HandoffParams {
  const empty: HandoffParams = {
    floor: null,
    unitRef: null,
    t: null,
    focusStop: null,
    patient: null,
    camera: null,
    layers: null,
    censusScope: null,
    windowPreset: null,
    selection: null,
    wall: false,
  };
  if (typeof window === 'undefined') return empty;
  const params = new URLSearchParams(window.location.search);

  const scope = params.get('scope');
  let floor: string | null = null;
  let unitRef: string | null = null;
  if (scope) {
    const [type, arg] = scope.split(':', 2);
    if (type === 'floor' && arg && /^\d+$/.test(arg)) floor = arg;
    if (type === 'unit' && arg) unitRef = arg;
  }

  let t: number | null = null;
  const rawT = params.get('t');
  if (rawT) {
    const parsed = Date.parse(rawT);
    if (Number.isFinite(parsed)) t = parsed;
  }

  const rawPatient = params.get('patient');
  const patient = rawPatient && /^ptok_[A-Za-z0-9]{24}$/.test(rawPatient) ? rawPatient : null;

  return {
    floor,
    unitRef,
    t,
    focusStop: params.get('focus_stop'),
    patient,
    ...parseViewState(window.location.search),
  };
}

function defaultLayersForLens(lens: FlowLens | null | undefined): PatientLayerState {
  if (!lens) {
    return { base: true, tokens: true, trails: true, heat: true, ghosts: true, barriers: true, rounds: true, pathway: false };
  }
  const has = (layer: string): boolean => lens.layers.includes(layer);
  const dots = lens.patient_dots !== 'none';
  return {
    base: true,
    tokens: has('events') && dots,
    trails: has('events') && dots,
    heat: has('snapshots'),
    ghosts: has('projections') && lens.projection_kinds.length > 0,
    // Barriers carry no patient identity (aggregate operational signal), so
    // every lens sees them by default — the operator can toggle them off.
    barriers: true,
    // Round stops are opaque tokens (no identity in the scene payload), so
    // the same doctrine applies; the toggle only appears when a run exists.
    rounds: true,
    // Phase C: deviation glyphs default OFF for every lens (§7.2) — the
    // operator opts in; the toggle only appears when the conformance flag
    // and a patient-dots lens compose.
    pathway: false,
  };
}

/** Census scope (B-1 family + §7.2 C3): which occupancy disks render. */
type NavigatorCensusScope = 'all' | 'delayed' | 'deviations';

/**
 * Lens redaction for the inspector (G7 on web): `none` → aggregate fields
 * only; `unit`/`task` → identity only when the item carries an opaque
 * patient_context_ref (flow replay events never do; some projections may).
 */
function redactSelection(
  data: Record<string, unknown>,
  dots: FlowPatientDots | null,
): Record<string, unknown> {
  if (!dots || dots === 'full') return data;
  const clone: Record<string, unknown> = { ...data };
  if (dots === 'none') {
    for (const key of [...IDENTITY_KEYS, 'patient_context_ref', 'entity']) delete clone[key];
  } else if (!clone.patient_context_ref) {
    for (const key of IDENTITY_KEYS) delete clone[key];
  }
  return clone;
}

/**
 * One timer, one readable evidence line — status, target, reason, owner.
 * Implementation provenance (source tables, record ids) deliberately stays out
 * of the operator panel (HFE audit EDDY-02: no raw JSON for decisions).
 */
function summarizeEvidenceRecord(record: Record<string, unknown>): string {
  const parts = [
    typeof record.status === 'string' ? record.status : null,
    typeof record.time_to_target === 'string' ? record.time_to_target : null,
    typeof record.reason === 'string' ? record.reason : null,
    typeof record.owner_role === 'string' ? `owner: ${record.owner_role}` : null,
    record.verified === true ? 'verified' : null,
  ].filter((part): part is string => part !== null);
  return parts.length > 0 ? parts.join(' · ') : '—';
}

function flattenInspector(data: Record<string, unknown>): Array<[string, string]> {
  const rows: Array<[string, string]> = [];

  for (const [key, value] of Object.entries(data)) {
    if (value === undefined || value === null || value === '') continue;

    if (Array.isArray(value) && value.length > 0 && value.every((item) => typeof item === 'object' && item !== null)) {
      // Arrays of records (timers) expand to one humanized row each — never
      // a serialized JSON blob.
      for (const item of value as Array<Record<string, unknown>>) {
        const label = typeof item.label === 'string' ? item.label : key.replaceAll('_', ' ');
        rows.push([label, summarizeEvidenceRecord(item)]);
      }
      continue;
    }

    rows.push([
      key.replaceAll('_', ' '),
      typeof value === 'object' ? JSON.stringify(value) : String(value),
    ]);
  }

  return rows.slice(0, 32);
}

function occupancyFilterKey(filters: PatientFlowFilters): string {
  return JSON.stringify({
    floor: filters.floor,
    serviceLine: filters.serviceLine,
    category: filters.category,
    search: filters.search.trim() ? 'local-search' : '',
  });
}

function isBarrierOrDelay(insight: OccupancyInsight): boolean {
  return insight.primaryStatus !== 'ok'
    || insight.blockers.length > 0
    || insight.timers.some((timer) => timer.status !== 'ok');
}

export default function PatientFlowNavigator({
  initialFloor = 'all',
  initialCategory = 'all',
  initialServiceLine = 'all',
  lens = null,
  units = [],
}: PatientFlowNavigatorProps) {
  // Fresh sources use the wall-clock 48h window. Stale sources move to an
  // explicit historical window after bootstrap so their replay stays usable.
  // The mount instant anchors bootstrap; `nowMs` then advances in state every
  // 60s so now-marker, ghost gating, and barrier open-age severity stay honest
  // on long-lived wall sessions (S-1).
  const mountedAtMs = useMemo(() => Date.now(), []);
  const [nowMs, setNowMs] = useState(mountedAtMs);
  const nowMsRef = useRef(mountedAtMs);
  const handoff = useMemo(() => parseHandoff(), []);
  const [timeWindow, setTimeWindow] = useState({
    start: mountedAtMs - LIVE_WINDOW_HALF_MS,
    end: mountedAtMs + LIVE_WINDOW_HALF_MS,
  });
  const windowStart = timeWindow.start;
  const windowEnd = timeWindow.end;

  const dotsPolicy: FlowPatientDots | null = lens?.patient_dots ?? null;
  const patientDotsVisible = dotsPolicy !== 'none';
  const page = usePage<PageProps>();
  const eddyEnabled = Boolean(page.props.eddy?.enabled);
  // Phase C (§7.2): ARENA ∧ FLOW4D_CONFORMANCE_ENABLED pre-composed server-side;
  // the lens leg composes here. Flag off → this file renders byte-identical.
  const conformanceEnabled = Boolean(page.props.arena?.conformance_enabled) && patientDotsVisible;
  const arenaAiEnabled = Boolean(page.props.arena?.ai_enabled);
  const openEddyWithPrefill = useEddyStore((state) => state.openWithPrefill);

  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const containerRef = useRef<HTMLDivElement | null>(null);
  const sceneRef = useRef<NavigatorScene | null>(null);
  const tracksRef = useRef<Map<string, PatientFlowEvent[]>>(new Map());
  const eventsRef = useRef<PatientFlowEvent[]>([]);
  const locationsRef = useRef<PatientFlowLocations>({});
  const filtersRef = useRef<PatientFlowFilters>({
    floor: handoff.floor ?? initialFloor,
    serviceLine: initialServiceLine,
    category: initialCategory,
    search: '',
  });
  // E5: a shared view link may carry an explicit layer set and census scope;
  // both stay clamped by the same gates as the interactive controls (a URL
  // can request, never grant — the lens and flags still decide what renders).
  const initialLayers = useMemo<PatientLayerState>(
    () => (handoff.layers ? { ...defaultLayersForLens(lens), ...handoff.layers } : defaultLayersForLens(lens)),
    [handoff.layers, lens],
  );
  const layersRef = useRef<PatientLayerState>(initialLayers);
  const censusScopeRef = useRef<NavigatorCensusScope>('all');
  // Phase C scene flags: ptok → worded chip state, refreshed on the
  // conformance poll; version feeds the heavy-layer bucket key.
  const deviationsRef = useRef<Map<string, string>>(new Map());
  const deviationsVersionRef = useRef(0);
  const conformanceCadenceRef = useRef(30);
  const projectionsRef = useRef<ProjectionItem[]>([]);
  const serverOccupancyRef = useRef<{
    asOfMs: number;
    filterKey: string;
    occupancy: OccupancyInsight[];
    summary: OccupancySummary;
  } | null>(null);
  const barriersRef = useRef<NavigatorBarrier[]>([]);
  const roundStopsRef = useRef<RoundStop[]>([]);
  const currentTimeRef = useRef(handoff.t ?? nowMs);
  const speedRef = useRef(60);
  const playingRef = useRef(false);
  const liveRef = useRef(false);
  const eventSourceRef = useRef<EventSource | null>(null);
  const lastVisibleStatesRef = useRef<PatientVisibleState[]>([]);
  const lastOccupancyInsightsRef = useRef<OccupancyInsight[]>([]);
  const lastBucketKeyRef = useRef('');
  const lastRoundsKeyRef = useRef('');
  const lastTimeEmitRef = useRef(0);
  const scopeAppliedRef = useRef(false);
  const inspectorInitializedRef = useRef(false);
  const occupancyRequestRef = useRef(0);
  // E5: one-shot guards for linked camera pose and aggregate selection —
  // an epoch rebootstrap must not re-yank the operator back to the link.
  const handoffCameraDoneRef = useRef(false);
  const handoffSelectionDoneRef = useRef(false);

  const [summary, setSummary] = useState<PatientFlowSummary | null>(null);
  const [ambient, setAmbient] = useState<PatientFlowAmbient | null>(null);
  const [locations, setLocations] = useState<PatientFlowLocations>({});
  const [events, setEvents] = useState<PatientFlowEvent[]>([]);
  const [projections, setProjections] = useState<ProjectionItem[]>([]);
  const [barriers, setBarriers] = useState<NavigatorBarrier[]>([]);
  const [roundStops, setRoundStops] = useState<RoundStop[]>([]);
  const [filters, setFilters] = useState<PatientFlowFilters>(filtersRef.current);
  const [layers, setLayers] = useState<PatientLayerState>(layersRef.current);
  // E5: honor a linked census scope; the deviations scope only exists when
  // the adherence surface composes for this page/persona.
  const [censusScope, setCensusScope] = useState<NavigatorCensusScope>(() => {
    if (handoff.censusScope === 'deviations') return conformanceEnabled ? 'deviations' : 'all';
    return handoff.censusScope ?? 'all';
  });
  // TN-6: chronobar window preset — 48h default; historical sources keep
  // their data-extent window regardless.
  const [windowPreset, setWindowPreset] = useState<WindowPreset>(handoff.windowPreset ?? '48h');
  const windowPresetRef = useRef(windowPreset);
  const [currentTime, setCurrentTime] = useState(currentTimeRef.current);
  const [speed, setSpeed] = useState(60);
  const [playing, setPlaying] = useState(false);
  const [live, setLive] = useState(false);
  const [status, setStatus] = useState('Loading');
  const [cameraPlace, setCameraPlace] = useState('');
  // Raw xyz debug readout is title-attribute-only — written via ref so a
  // camera emit never re-renders the tree when the place label is unchanged.
  const cameraSpanRef = useRef<HTMLSpanElement | null>(null);
  // E1: latest camera pose for the minimap — a ref (not state) so the ~7 Hz
  // camera stream never re-renders the orchestrator; the minimap polls it.
  const cameraViewRef = useRef<CameraView | null>(null);
  // E1: floor location codes with an observed deviation, for the minimap pips.
  // Guarded setState from refreshScene (change-keyed) so the hot path is cheap.
  const [deviantLocationCodes, setDeviantLocationCodes] = useState<string[]>([]);
  const deviantLocationKeyRef = useRef('');
  const [metrics, setMetrics] = useState<NavigatorMetrics>({ active: 0, events: 0, occupiedLocations: 0 });
  // F-8 non-pointer parity: the delayed/watch locations currently drawn in the
  // scene, exposed as a keyboard-selectable list (NavigatorActionList).
  const [actionableInsights, setActionableInsights] = useState<OccupancyInsight[]>([]);
  const [occupancy, setOccupancy] = useState<OccupancySummary>(EMPTY_OCCUPANCY_SUMMARY);
  const [forecast, setForecast] = useState<ForecastAggregates | null>(null);
  const [inspectorTitle, setInspectorTitle] = useState('Select a patient or location');
  const [inspectorRows, setInspectorRows] = useState<Array<[string, string]>>([]);
  // F-6 pt 2 — dataset-epoch rebootstrap: bumping the nonce re-runs the whole
  // bootstrap atomically (all four datasets together, never one at a time).
  const [bootstrapNonce, setBootstrapNonce] = useState(0);
  // Bumped when the lazy scene chunk finishes mounting, so effects that
  // mirror React state onto the scene re-run against a live sceneRef.
  const [sceneNonce, setSceneNonce] = useState(0);
  const [rebuildNotice, setRebuildNotice] = useState<string | null>(null);
  const epochRef = useRef<string | null>(null);
  const rebootstrappingRef = useRef(false);

  // ---- Patient Journey Drawer (plan §7.1 B, PJ-1) --------------------------
  // The drawer replaces the inspector for PATIENT selections only; on a 403
  // or fetch failure it falls back to the inspector (status line explains).
  const [journeyState, setJourneyState] = useState<'idle' | 'loading' | 'ok'>('idle');
  const [journeyData, setJourneyData] = useState<PatientJourney | null>(null);
  const [journeyAlign, setJourneyAlign] = useState<JourneyAlignAnchor>('clock');
  const [journeyFollow, setJourneyFollow] = useState(false);
  const [journeyLinkCopied, setJourneyLinkCopied] = useState(false);
  // Phase C: the open journey's cached adherence verdicts (null = surface off
  // for this page/persona — the drawer renders byte-identical to Phase B).
  const [adherence, setAdherence] = useState<DrawerAdherence | null>(null);
  const journeyPatientRef = useRef<string | null>(null);
  const journeyFollowRef = useRef(false);
  useEffect(() => {
    journeyFollowRef.current = journeyFollow;
  }, [journeyFollow]);

  // One deliberate entry point for reloading the WORLD: the demo refresh
  // rebased every timestamp, so partial refetches would mix epochs. Clears
  // selection (the old mesh describes a dataset that no longer exists),
  // shows a quiet notice, and re-runs the bootstrap atomically.
  const requestRebootstrap = useCallback((notice: string) => {
    if (rebootstrappingRef.current) return;
    rebootstrappingRef.current = true;
    setRebuildNotice(notice);
    setError(null);
    sceneRef.current?.clearSelection();
    setInspectorTitle('Select a patient or location');
    setInspectorRows([]);
    setInspectorAction(null);
    setBootstrapNonce((nonce) => nonce + 1);
  }, []);
  const [feed, setFeed] = useState<PatientFlowEvent[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [searchMatches, setSearchMatches] = useState<number | null>(null);
  const [searchResults, setSearchResults] = useState<Array<{ patientId: string; label: string }>>([]);
  const [shortcutsOpen, setShortcutsOpen] = useState(false);
  // E3: top-down orthographic plan view (the `O` key + toolbar toggle).
  const [ortho, setOrtho] = useState(false);
  // E4: GSTC flatten — draw the last-6h trail density on the 3D floor.
  const [floorHeatOn, setFloorHeatOn] = useState(false);
  const [roundsRun, setRoundsRun] = useState<RunSummary | null>(null);
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [inspectorAction, setInspectorAction] = useState<{ label: string; href: string } | null>(null);
  const [tourAuto, setTourAuto] = useState(false);
  const [focusTick, setFocusTick] = useState(0);
  const roundsRunRef = useRef<RunSummary | null>(null);
  const roundsVersionRef = useRef(0);
  const roundsSceneHashRef = useRef('');
  const roundsRunUuidRef = useRef<string | null>(null);
  const roundsFailCountRef = useRef(0);
  const pendingFocusStopRef = useRef<string | null>(handoff.focusStop);
  const focusFloorClearedRef = useRef(false);
  const focusAttemptsRef = useRef(0);
  const tourIndexRef = useRef<number | null>(null);
  // Tour anchor is the stop UUID, not the array index — the stops list
  // reorders and shrinks on every 30 s poll.
  const tourUuidRef = useRef<string | null>(null);
  const tourAutoRef = useRef(false);
  const tourStepRef = useRef<(direction: 1 | -1) => void>(() => {});
  // Live-follow is an explicit mode, not a proximity guess: entered at
  // bootstrap (fresh source) or by scrubbing to now; any deliberate scrub
  // elsewhere leaves it.
  const followNowRef = useRef(handoff.t === null);
  const viewsStorageKey = savedViewsKey(lens?.role_id);
  const [views, setViews] = useState<Array<SavedView | null>>(() => {
    // Blocked storage (kiosk privacy mode, sandboxed embed) must degrade to
    // empty slots, never crash the navigator at mount.
    try {
      return parseSavedViews(typeof window === 'undefined' ? null : window.localStorage.getItem(viewsStorageKey));
    } catch {
      return parseSavedViews(null);
    }
  });

  // If the persona lens changes without a remount, re-read that persona's
  // slots — otherwise a save would clobber the new persona's bookmarks with
  // the old persona's array.
  useEffect(() => {
    try {
      setViews(parseSavedViews(window.localStorage.getItem(viewsStorageKey)));
    } catch {
      setViews(parseSavedViews(null));
    }
  }, [viewsStorageKey]);

  // H5.1 first-run intro: persona-keyed one-time dismissal under
  // `flow4d.tour.{role}`. Blocked storage never auto-starts (introTour.ts) —
  // a kiosk wall on the 6h demo refresh must not loop the welcome card.
  const introStorageKey = introTourKey(lens?.role_id);
  const [introOpen, setIntroOpen] = useState(() =>
    shouldAutoStartIntro(() => window.localStorage.getItem(introTourKey(lens?.role_id))),
  );
  const [introIndex, setIntroIndex] = useState(0);
  const introOpenRef = useRef(introOpen);
  useEffect(() => {
    introOpenRef.current = introOpen;
  }, [introOpen]);

  // A persona switch without a remount re-evaluates that persona's dismissal.
  useEffect(() => {
    setIntroIndex(0);
    setIntroOpen(shouldAutoStartIntro(() => window.localStorage.getItem(introStorageKey)));
  }, [introStorageKey]);

  const dismissIntro = useCallback(() => {
    if (!introOpenRef.current) return;
    setIntroOpen(false);
    persistIntroSeen(() => window.localStorage.setItem(introStorageKey, INTRO_SEEN));
  }, [introStorageKey]);

  const roundsActive = roundsRun !== null;
  const introStopList = useMemo(() => introStops(roundsActive), [roundsActive]);

  const tracks = useMemo(() => rebuildTracks(events), [events]);

  // E1: deviant floor-location codes as a Set for the minimap pips.
  const deviationLocationSet = useMemo(() => new Set(deviantLocationCodes), [deviantLocationCodes]);

  // S-1: advance wall-clock now every 60s. The ref updates in the same tick so
  // any repaint that fires before the effects run already sees the fresh value.
  // The same tick carries the F-6 epoch check — a cheap aggregate read; when
  // the demo refresh lands a new epoch the whole view rebootstraps atomically.
  useEffect(() => {
    const id = window.setInterval(() => {
      nowMsRef.current = Date.now();
      setNowMs(nowMsRef.current);

      if (document.visibilityState !== 'hidden' && !rebootstrappingRef.current) {
        fetchPatientFlowEpoch()
          .then((next) => {
            const adoption = adoptEpoch(epochRef.current, next);
            epochRef.current = adoption.epoch;
            if (adoption.changed) {
              requestRebootstrap('Data refreshed — rebuilding view');
            }
          })
          .catch(() => { /* epoch is a convenience signal; never break the tick */ });
      }
    }, 60_000);
    return () => window.clearInterval(id);
  }, [requestRebootstrap]);

  const dataStart = useMemo(() => (events.length ? parseTime(events[0].occurred_at) : null), [events]);
  const dataEnd = useMemo(
    () => (events.length ? parseTime(events[events.length - 1].occurred_at) : null),
    [events],
  );
  const historical = summary?.source.freshness === 'stale' && dataEnd !== null;

  // Mirrored into refs for the scene's onFrame closure, so the three.js scene
  // is NOT torn down and rebuilt when the live window slides (S-1) or the
  // source flips historical after bootstrap.
  const historicalRef = useRef(false);
  const windowRef = useRef(timeWindow);
  useEffect(() => {
    historicalRef.current = historical;
    windowRef.current = timeWindow;
  }, [historical, timeWindow]);

  // When each open barrier began, for chronobar ticks (past half only).
  const barrierTicks = useMemo(
    () => barriers
      .map((barrier) => (barrier.opened_at ? Date.parse(barrier.opened_at) : Number.NaN))
      .filter((ms) => Number.isFinite(ms) && ms <= nowMs),
    [barriers, nowMs],
  );

  // B5 — the scented scrubber: house event density across the window, so
  // retrospective scrubbing is guided instead of blind (TN-1).
  const chronobarDensity = useMemo(() => eventDensityBuckets(
    events.map((event) => parseTime(event.occurred_at) ?? Number.NaN),
    windowStart,
    windowEnd,
  ), [events, windowEnd, windowStart]);

  // B3/B5 — the open journey's event instants as jump ticks.
  const chronobarPatientTicks = useMemo(
    () => (journeyData ? journeyEventTicks(journeyData) : []),
    [journeyData],
  );

  const placementIndex = useMemo(
    () => buildProjectionPlacementIndex(locations, units),
    [locations, units],
  );
  const placementIndexRef = useRef(placementIndex);

  const floors = useMemo(() => {
    return [...new Set(Object.values(locations).map((loc) => loc.floor).filter((value): value is number => value !== null && value !== undefined))]
      .sort((a, b) => a - b)
      .map(String);
  }, [locations]);

  const services = useMemo(() => {
    return [...new Set(events.map((event) => event.service_line).filter((value): value is string => Boolean(value)))].sort();
  }, [events]);

  const categories = useMemo(() => {
    return [...new Set(events.map((event) => event.event_category).filter(Boolean))].sort();
  }, [events]);

  const layerControls = useMemo<LayerControl[]>(() => {
    const controls: LayerControl[] = [{ key: 'base', label: 'Model', id: 'flow-layer-model' }];
    if (patientDotsVisible) {
      controls.push(
        { key: 'tokens', label: 'Patients', id: 'flow-layer-patients' },
        { key: 'trails', label: 'Trails', id: 'flow-layer-trails' },
      );
    }
    controls.push({ key: 'heat', label: 'Census', id: 'flow-layer-census' });
    // "Barriers" here = the diamond markers for logged prod.barriers rows —
    // a different concept from the "Delayed only" census scope (B-1).
    controls.push({
      key: 'barriers',
      label: 'Barriers',
      id: 'flow-layer-barriers',
      title: 'Logged operational barriers (diamond markers)',
    });
    if (!lens || (lens.layers.includes('projections') && lens.projection_kinds.length > 0)) {
      controls.push({ key: 'ghosts', label: 'Forecast', id: 'flow-layer-forecast' });
    }
    // Rounds overlay toggle only surfaces when an open run produced stops
    // (feature flag off / no run today → the navigator stays exactly as-is).
    if (roundStops.length > 0) {
      controls.push({ key: 'rounds', label: 'Rounds', id: 'flow-layer-rounds' });
    }
    // Phase C: the deviation-glyph layer — flag ∧ patient-dots composed;
    // default off (the operator opts in), absent entirely while dark.
    if (conformanceEnabled) {
      controls.push({
        key: 'pathway',
        label: 'Pathway',
        id: 'flow-layer-pathway',
        title: 'Care-pathway deviation glyphs (30-minute conformance batch)',
      });
    }
    return controls;
  }, [conformanceEnabled, lens, patientDotsVisible, roundStops.length]);

  // ---- scene refresh: cheap per-frame tokens, bucketed heavy layers -------
  // Reads wall-clock now from the ref so the callback identity stays stable
  // across S-1 ticks (the scene effect must not rebuild three.js every 60s).
  const refreshScene = useCallback(() => {
    const scene = sceneRef.current;
    if (!scene) return;

    const wallNowMs = nowMsRef.current;
    const timeMs = currentTimeRef.current;
    const states = patientStatesAt(tracksRef.current, locationsRef.current, timeMs, filtersRef.current);
    const localOccupancy = buildOccupancyInsights(
      states,
      locationsRef.current,
      projectionsRef.current,
      timeMs,
      lens,
    );
    const serverOccupancy = serverOccupancyRef.current;
    const useServerOccupancy = Boolean(
      serverOccupancy
        && Math.abs(serverOccupancy.asOfMs - timeMs) < 60_000
        && serverOccupancy.filterKey === occupancyFilterKey(filtersRef.current)
        && filtersRef.current.search.trim() === '',
    );
    const occupancyInsights = useServerOccupancy ? serverOccupancy!.occupancy : localOccupancy.insights;
    const occupancySummary = useServerOccupancy ? serverOccupancy!.summary : localOccupancy.summary;
    // C3: the deviations census scope isolates the LOCATIONS of flagged
    // patients — same chip/metric/Focus discipline as the delayed scope.
    const scope = censusScopeRef.current;
    const deviantLocations = scope === 'deviations'
      ? new Set(states
          .filter((state) => deviationsRef.current.has(state.patientId))
          .map((state) => state.event.to_location)
          .filter(Boolean))
      : null;
    const visibleOccupancyInsights = scope === 'delayed'
      ? occupancyInsights.filter(isBarrierOrDelay)
      : deviantLocations !== null
        ? occupancyInsights.filter((insight) => deviantLocations.has(insight.location))
        : occupancyInsights;
    lastVisibleStatesRef.current = states;
    lastOccupancyInsightsRef.current = occupancyInsights;
    // Only the visible disks are selectable in the scene; the list mirrors them.
    setActionableInsights(visibleOccupancyInsights.filter((insight) => insight.primaryStatus !== 'ok'));
    // E1 minimap deviation pips: the location codes of currently-visible
    // flagged patients. Change-keyed so the hot path only re-renders the
    // minimap when the set actually shifts. Empty (and free) while dark.
    if (deviationsRef.current.size > 0) {
      const codes = [...new Set(states
        .filter((state) => deviationsRef.current.has(state.patientId))
        .map((state) => state.event.to_location)
        .filter((code): code is string => Boolean(code)))].sort();
      const key = codes.join('|');
      if (key !== deviantLocationKeyRef.current) {
        deviantLocationKeyRef.current = key;
        setDeviantLocationCodes(codes);
      }
    } else if (deviantLocationKeyRef.current !== '') {
      deviantLocationKeyRef.current = '';
      setDeviantLocationCodes([]);
    }
    scene.setPathwayDeviations(deviationsRef.current, layersRef.current.pathway);
    scene.updateTokens(
      states,
      layersRef.current.tokens && dotsPolicy !== 'none',
      dotsPolicy !== null && dotsPolicy !== 'full',
    );

    // Heavy layers rebuild only when the sim-time minute bucket, filters,
    // layers, or datasets change — not every animation frame.
    const bucketKey = [
      Math.floor(timeMs / 60_000),
      Math.floor(wallNowMs / 60_000),
      JSON.stringify(filtersRef.current),
      JSON.stringify(layersRef.current),
      censusScopeRef.current,
      deviationsVersionRef.current,
      eventsRef.current.length,
      projectionsRef.current.length,
      barriersRef.current.length,
      // Content version, not length: a status flip with the same stop count
      // must still rebuild the rings (R-3).
      roundsVersionRef.current,
      Object.keys(locationsRef.current).length,
    ].join('|');
    if (bucketKey === lastBucketKeyRef.current) return;
    lastBucketKeyRef.current = bucketKey;

    scene.setBaseVisibility(filtersRef.current.floor, layersRef.current.base);
    scene.rebuildTrails(
      tracksRef.current,
      locationsRef.current,
      states,
      timeMs,
      layersRef.current.trails && patientDotsVisible,
    );
    const occupied = scene.rebuildHeat(visibleOccupancyInsights, layersRef.current.heat);

    // Projection ghosts + forecast heat (the future half; §5 ghost grammar).
    const index = placementIndexRef.current;
    const floorFilter = filtersRef.current.floor;
    const ghostItems = layersRef.current.ghosts
      ? ghostsAt(projectionsRef.current, wallNowMs, timeMs).filter((item) => {
          if (floorFilter === 'all') return true;
          const floor = floorForProjection(item, index);
          return floor !== null && String(floor) === floorFilter;
        })
      : [];

    const ghostTokens = ghostItems
      .filter((item) => ENTITY_PROJECTION_KINDS.includes(item.kind))
      .map((item) => {
        const anchor = anchorForProjection(item, index);
        return anchor ? { item, anchor } : null;
      })
      .filter((ghost): ghost is NonNullable<typeof ghost> => ghost !== null);
    scene.rebuildGhosts(ghostTokens, layersRef.current.ghosts);

    const aggregates = layersRef.current.ghosts
      ? aggregatesAt(projectionsRef.current, wallNowMs, timeMs)
      : null;
    const heatCells = aggregates
      ? [...aggregates.censusByUnit.entries()]
          .filter(([unitId]) => {
            if (floorFilter === 'all') return true;
            const floor = index.unitFloors.get(unitId);
            return floor !== undefined && String(floor) === floorFilter;
          })
          .map(([unitId, item]) => {
            const anchor = index.unitAnchors.get(unitId);
            return anchor && item.value !== null
              ? { anchor, value: item.value, opacity: Math.min(0.3, confidenceOpacity(item.confidence) * 0.45) }
              : null;
          })
          .filter((cell): cell is NonNullable<typeof cell> => cell !== null)
      : [];
    scene.rebuildForecastHeat(heatCells, layersRef.current.ghosts && timeMs > wallNowMs);
    setForecast(aggregates && timeMs > wallNowMs ? aggregates : null);
    setOccupancy(occupancySummary);

    // Open-barrier markers — present-state, so shown at every scrub position
    // (not gated on past/future), just placed on their unit + floor-filtered.
    const barrierCells = layersRef.current.barriers
      ? buildBarrierCells(barriersRef.current, index, floorFilter, wallNowMs)
      : [];
    scene.rebuildBarriers(barrierCells, layersRef.current.barriers);

    // Round-stop rings — present-state like barriers: shown at every scrub
    // position, floor-filtered, opaque tokens only (plan §8.1). The route
    // polyline and queue numbers ride the same rebuild (R-4). The layer is
    // time-independent, so it rebuilds on its OWN key (content version /
    // floor / visibility), not on every minute bucket.
    const roundsKey = `${roundsVersionRef.current}|${floorFilter}|${layersRef.current.rounds ? 1 : 0}`;
    if (roundsKey !== lastRoundsKeyRef.current) {
      lastRoundsKeyRef.current = roundsKey;
      const roundCells = layersRef.current.rounds
        ? buildRoundStopCells(roundStopsRef.current, index, floorFilter)
        : [];
      scene.rebuildRounds(roundCells, buildRoundRoute(roundCells), layersRef.current.rounds);
    }

    setMetrics({
      active: scope !== 'all' ? visibleOccupancyInsights.length : (useServerOccupancy ? occupancySummary.active : states.length),
      events: eventsRef.current.filter((event) => parseTime(event.occurred_at) <= timeMs).length,
      occupiedLocations: occupied
        || new Set((scope !== 'all' || useServerOccupancy ? visibleOccupancyInsights.map((item) => item.location) : states.map((state) => state.event.to_location)).filter(Boolean)).size,
    });
    // N-5: the Find field shows how many tokens the search matched.
    // H1.2: the first matches render as a selectable list — the keyboard/AT
    // path to selection. Labels honor the lens (identity only on full dots).
    const searching = filtersRef.current.search.trim() !== '';
    setSearchMatches(searching ? states.length : null);
    setSearchResults(searching
      ? states.slice(0, 8).map((state) => ({
          patientId: state.patientId,
          label: dotsPolicy === null || dotsPolicy === 'full'
            ? (state.event.patient_display_id ?? state.patientId)
            : (state.event.to_location ?? 'Unknown location'),
        }))
      : []);
  }, [dotsPolicy, lens, patientDotsVisible]);

  // Keep refs in sync with state, then repaint.
  useEffect(() => {
    eventsRef.current = events;
    locationsRef.current = locations;
    filtersRef.current = filters;
    layersRef.current = layers;
    censusScopeRef.current = censusScope;
    projectionsRef.current = projections;
    barriersRef.current = barriers;
    roundStopsRef.current = roundStops;
    speedRef.current = speed;
    playingRef.current = playing;
    liveRef.current = live;
    tracksRef.current = tracks;
    placementIndexRef.current = placementIndex;
    refreshScene();
  }, [events, locations, filters, layers, censusScope, projections, barriers, roundStops, speed, playing, live, tracks, placementIndex, refreshScene]);

  // B-4: no camera side effect here — flying to the scoped set is an explicit
  // "Focus" action on the filter chip, never a consequence of toggling scope.
  useEffect(() => {
    censusScopeRef.current = censusScope;
    lastBucketKeyRef.current = '';
    refreshScene();
  }, [censusScope, refreshScene]);

  // S-1: repaint when wall-clock now advances (the now-minute is part of the
  // heavy-layer bucket key, so severity and gating rebuild with the fresh now).
  useEffect(() => {
    nowMsRef.current = nowMs;
    refreshScene();
  }, [nowMs, refreshScene]);

  // H4 soak hook: pull-based diagnostics for scripts/soak-flow4d.mjs. Getters
  // read refs so one install on mount stays current; nowDeltaMs is only
  // meaningful in follow mode (a deliberate scrub is not clock drift).
  useEffect(() => {
    roundsRunRef.current = roundsRun;
  }, [roundsRun]);
  useEffect(() => installSoakHook({
    rendererInfo: () => sceneRef.current?.debugInfo() ?? null,
    nowDeltaMs: () => (followNowRef.current ? Date.now() - nowMsRef.current : null),
    roundsRun: () => (roundsRunRef.current
      ? { uuid: roundsRunRef.current.run_uuid, status: roundsRunRef.current.status }
      : null),
    epoch: () => epochRef.current,
    pathwayGlyphs: () => sceneRef.current?.pathwayGlyphCount() ?? null,
  }), []);

  // Live-follow: slide the 48h window with wall-clock now, but only in
  // explicit follow mode (entered at bootstrap or by scrubbing to now) — a
  // deliberate scrub near now, a playback sweep, or a connected replay
  // stream is never yanked.
  useEffect(() => {
    if (historical || playingRef.current || liveRef.current) return;
    if (!followNowRef.current) return;
    if (Math.abs(currentTimeRef.current - nowMs) >= 90_000) return;
    setTimeWindow(windowForPreset(windowPresetRef.current, nowMs));
    currentTimeRef.current = nowMs;
    setCurrentTime(nowMs);
  }, [historical, nowMs]);

  // Repaint when the displayed time changes. The ref is the source of truth
  // (playback advances it per frame); scrub/live paths write it via applyTime.
  useEffect(() => {
    refreshScene();
  }, [currentTime, refreshScene]);

  useEffect(() => {
    const requestId = ++occupancyRequestRef.current;
    if (!summary || !lens || filters.search.trim() !== '') return;
    const asOf = new Date(currentTime).toISOString();
    const filterKey = occupancyFilterKey(filters);
    const timer = window.setTimeout(() => {
      fetchPatientFlowOccupancy({
        asOf,
        persona: lens.role_id,
        floor: filters.floor !== 'all' ? filters.floor : undefined,
        service_line: filters.serviceLine !== 'all' ? filters.serviceLine : undefined,
        category: filters.category !== 'all' ? filters.category : undefined,
        limit: 20000,
        include: 'eddy_context',
        })
        .then((payload) => {
          if (requestId !== occupancyRequestRef.current) return;
          serverOccupancyRef.current = {
            asOfMs: Date.parse(payload.asOf),
            filterKey,
            occupancy: payload.occupancy,
            summary: payload.summary,
          };
          lastBucketKeyRef.current = '';
          setOccupancy(payload.summary);
          if (!inspectorInitializedRef.current) {
            const priority = payload.occupancy.find(isBarrierOrDelay);
            if (priority) {
              inspectorInitializedRef.current = true;
              const detail = redactSelection(occupancyInspectorData(priority), dotsPolicy);
              setInspectorTitle(`${priority.locationName ?? priority.location} - delay detail`);
              setInspectorRows(flattenInspector(detail));
            }
          }
          refreshScene();
        })
        .catch(() => {
          if (requestId !== occupancyRequestRef.current) return;
          serverOccupancyRef.current = null;
        });
    }, playing ? 900 : 220);

    return () => window.clearTimeout(timer);
  }, [currentTime, dotsPolicy, filters, lens, playing, refreshScene, summary]);

  const applyTime = useCallback((timeMs: number): void => {
    currentTimeRef.current = timeMs;
    setCurrentTime(timeMs);
  }, []);

  // TN-6: switching presets resizes the window around now and clamps the
  // scrub position into it. The default 48h path is identical to before.
  const applyWindowPreset = useCallback((preset: WindowPreset): void => {
    windowPresetRef.current = preset;
    setWindowPreset(preset);
    if (historicalRef.current) return;
    const next = windowForPreset(preset, nowMsRef.current);
    setTimeWindow(next);
    const clamped = Math.min(next.end, Math.max(next.start, currentTimeRef.current));
    if (clamped !== currentTimeRef.current) applyTime(clamped);
  }, [applyTime]);

  // N-3: the camera readout speaks place, not xyz — the nearest unit centroid
  // to the orbit target names what the operator is looking at. Raw coordinates
  // survive in the status-bar title attribute for debugging.
  const handleCameraMove = useCallback((view: CameraView): void => {
    cameraViewRef.current = view;
    if (cameraSpanRef.current) {
      cameraSpanRef.current.title =
        `camera x ${Math.round(view.position.x)} y ${Math.round(view.position.y)} z ${Math.round(view.position.z)}`
        + ` · target x ${Math.round(view.target.x)} z ${Math.round(view.target.z)}`;
    }

    // Wide framing (home / fit-to-floor distance) is an overview, and naming
    // the incidentally-nearest unit there would mislead.
    const range = Math.hypot(
      view.position.x - view.target.x,
      view.position.y - view.target.y,
      view.position.z - view.target.z,
    );
    if (range > 150) {
      const floorFilter = filtersRef.current.floor;
      setCameraPlace(floorFilter === 'all' ? 'House view' : `Floor ${floorFilter} · overview`);
      return;
    }

    const index = placementIndexRef.current;
    let bestUnitId: number | null = null;
    let bestDistSq = Number.POSITIVE_INFINITY;
    for (const [unitId, anchor] of index.unitAnchors) {
      const dx = anchor.x - view.target.x;
      const dz = anchor.z - view.target.z;
      const distSq = dx * dx + dz * dz;
      if (distSq < bestDistSq) {
        bestDistSq = distSq;
        bestUnitId = unitId;
      }
    }
    if (bestUnitId === null) {
      setCameraPlace('');
      return;
    }

    const unit = units.find((candidate) => candidate.unit_id === bestUnitId);
    const unitLabel = unit?.name
      ?? unit?.unit_code?.toUpperCase()
      ?? index.unitCodeById.get(bestUnitId)?.toUpperCase()
      ?? `Unit ${bestUnitId}`;
    const floor = index.unitFloors.get(bestUnitId);
    setCameraPlace(floor !== undefined ? `Floor ${floor} · ${unitLabel}` : unitLabel);
  }, [units]);

  // ---- data bootstrap ------------------------------------------------------
  useEffect(() => {
    let cancelled = false;

    async function bootstrap(): Promise<void> {
      try {
        setStatus('Loading data');
        // F-1 ruling: every lensed request forwards the page persona so the
        // resolved lens can never diverge from the rendered page.
        const [summaryData, locationData, eventData, ambientData] = await Promise.all([
          fetchPatientFlowSummary(lens?.role_id),
          fetchPatientFlowLocations(lens?.role_id),
          patientDotsVisible
            ? fetchPatientFlowEvents({ limit: 20000, persona: lens?.role_id })
            : Promise.resolve([]),
          fetchPatientFlowAmbient(lens?.role_id),
        ]);
        if (cancelled) return;

        // A rebootstrap lands in the NEW epoch's frame: the deep-link time and
        // the original mount anchor both describe the pre-refresh world, so
        // only the first bootstrap honors them (F-6 pt 2).
        const anchorMs = bootstrapNonce === 0 ? mountedAtMs : Date.now();
        const handoffTime = bootstrapNonce === 0 ? handoff.t : undefined;
        const prepared = prepareReplay(summaryData, eventData, anchorMs, handoffTime);
        const { events: sortedEvents, timeline } = prepared;
        epochRef.current = summaryData.epoch?.epoch ?? epochRef.current;
        setSummary(summaryData);
        setAmbient(ambientData);
        setLocations(locationData);
        setEvents(sortedEvents);
        setFeed(recentReplayEvents(sortedEvents));
        // TN-6: a narrowed preset survives bootstrap (deep links, epoch
        // rebootstraps); historical sources always keep the extent window.
        setTimeWindow(timeline.historical || windowPresetRef.current === '48h'
          ? { start: timeline.windowStart, end: timeline.windowEnd }
          : windowForPreset(windowPresetRef.current, anchorMs));
        applyTime(timeline.currentTime);
        setError(null);
        setRebuildNotice(null);
        if (!patientDotsVisible) {
          setStatus('Aggregate persona lens');
        } else {
          setStatus(replayStatus(timeline));
        }
      } catch (caught) {
        const message = caught instanceof Error ? caught.message : 'Unable to load patient flow data';
        setError(message);
        setRebuildNotice(null);
        setStatus('Load failed');
      } finally {
        rebootstrappingRef.current = false;
      }
    }

    void bootstrap();

    return () => {
      cancelled = true;
    };
  }, [applyTime, bootstrapNonce, handoff.t, mountedAtMs, patientDotsVisible]);

  // Projection stream (future half) — lens-clamped server-side; a failure
  // only disables ghosts, never the navigator. Re-polled every 5 min (S-2);
  // hidden tabs skip the poll and catch up on the visibilitychange that
  // brings them back.
  useEffect(() => {
    let cancelled = false;

    const load = (): void => {
      if (document.visibilityState === 'hidden') return;
      fetchPatientFlowProjections(lens ? { persona: lens.role_id } : {})
        .then((payload) => {
          if (cancelled) return;
          const allowed = lens ? new Set(lens.projection_kinds) : null;
          setProjections(payload.projections.filter((item) => !allowed || allowed.has(item.kind)));
        })
        .catch(() => {
          if (!cancelled) setProjections([]);
        });
    };

    load();
    const timer = window.setInterval(load, 300_000);
    document.addEventListener('visibilitychange', load);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', load);
    };
  }, [lens]);

  // Open barriers overlay — aggregate + patient-free (no lens needed); a failure
  // only hides the overlay, never the navigator. Re-polled every 120 s (S-2)
  // with the same visibility gating so wall displays see new/closed barriers.
  useEffect(() => {
    let cancelled = false;

    const load = (): void => {
      if (document.visibilityState === 'hidden') return;
      fetchPatientFlowBarriers()
        .then((payload) => {
          if (!cancelled) setBarriers(payload.open_barriers);
        })
        .catch(() => {
          if (!cancelled) setBarriers([]);
        });
    };

    load();
    const timer = window.setInterval(load, 120_000);
    document.addEventListener('visibilitychange', load);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', load);
    };
  }, []);

  // Phase C scene flags (§7.2 C3) — which visible patients carry a cached
  // non-conformant verdict, keyed by ptok. Cache-only server-side, 30-minute
  // batch; polled every 5 min (barriers-overlay idiom) so a wall picks up a
  // fresh batch mid-session. Any failure empties the layer, never the
  // navigator. Gated: absent flag/persona means this effect never fetches.
  useEffect(() => {
    if (!conformanceEnabled) return undefined;
    let cancelled = false;

    const apply = (entries: Map<string, string>, cadence: number | null): void => {
      deviationsRef.current = entries;
      deviationsVersionRef.current += 1;
      if (cadence && cadence > 0) conformanceCadenceRef.current = cadence;
      lastBucketKeyRef.current = '';
      refreshScene();
    };

    const load = (): void => {
      if (document.visibilityState === 'hidden') return;
      void fetchSceneConformance({ persona: lens?.role_id }).then((result) => {
        if (cancelled) return;
        if (result.kind !== 'ok' || !result.data.available) {
          apply(new Map(), null);
          return;
        }
        const entries = new Map<string, string>();
        for (const patient of result.data.patients ?? []) {
          entries.set(patient.ref, sceneChipLabel(
            patient.pathways.map((flag) => ({ pathway: flag.pathway, deviations: flag.deviations ?? [] })),
          ));
        }
        apply(entries, result.data.cadence_minutes ?? null);
      });
    };

    load();
    const timer = window.setInterval(load, 300_000);
    document.addEventListener('visibilitychange', load);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', load);
    };
  }, [conformanceEnabled, lens?.role_id, refreshScene]);

  // Virtual Rounds overlay (plan §8.1) — the most recent open run's scene
  // stops, opaque tokens only. Feature flag off (404), no run, or any failure
  // simply leaves the overlay empty; the navigator never degrades.
  // R-3: polled every 30 s (mirroring the board's cadence) with a content-hash
  // gate (run identity + status + stops) so unchanged payloads never touch
  // React state or the scene. Polling NEVER stops — a wall display must pick
  // up tomorrow's run after today's completes; the open→closed transition
  // announces completion exactly once per run (uuid ref nulled on announce).
  useEffect(() => {
    let cancelled = false;

    async function loadRoundsOverlay(): Promise<void> {
      if (document.visibilityState === 'hidden') return;
      try {
        const runsPayload = runsResponseSchema.safeParse(await fetchRoundRuns());
        if (!runsPayload.success || cancelled) return;
        roundsFailCountRef.current = 0;

        const openRun = findOpenRun(runsPayload.data.data);
        if (!openRun) {
          if (roundsRunUuidRef.current !== null) {
            // Run just went terminal (completed/cancelled) or was retired by the
            // demo refresh: announce once AND drop the rings, so a finished run's
            // itinerary can't linger in the scene as if it were live work
            // (HFE audit F-6). Mirrors the persistent-failure clear below.
            roundsRunUuidRef.current = null;
            roundsSceneHashRef.current = '';
            roundsVersionRef.current += 1;
            setRoundsRun((prev) => (prev ? { ...prev, status: 'completed' } : prev));
            setRoundStops([]);
            setToastMessage('Rounds run complete');
          }
          return;
        }
        if (roundsRunUuidRef.current !== openRun.run_uuid) {
          // New run (or first run): drop the previous run's content hash so
          // its scene payload always applies.
          roundsSceneHashRef.current = '';
        }
        roundsRunUuidRef.current = openRun.run_uuid;

        const scenePayload = sceneResponseSchema.safeParse(
          await fetchRoundScene(openRun.run_uuid, lens?.role_id),
        );
        if (!scenePayload.success || cancelled) return;

        const { run, stops } = scenePayload.data.data;
        const hash = `${run.run_uuid}|${run.status}|${JSON.stringify(stops)}`;
        if (hash !== roundsSceneHashRef.current) {
          roundsSceneHashRef.current = hash;
          roundsVersionRef.current += 1;
          setRoundsRun(run);
          setRoundStops(stops);
        }
      } catch {
        if (cancelled) return;
        // Transient failure keeps the last overlay (never blank an active
        // itinerary on one blip) — but a persistently failing source must not
        // present hours-stale rings as truth.
        roundsFailCountRef.current += 1;
        if (roundsFailCountRef.current >= 5 && roundsRunUuidRef.current !== null) {
          roundsRunUuidRef.current = null;
          roundsSceneHashRef.current = '';
          roundsVersionRef.current += 1;
          setRoundsRun(null);
          setRoundStops([]);
        }
      }
    }

    void loadRoundsOverlay();
    const timer = window.setInterval(() => void loadRoundsOverlay(), 30_000);
    const onVisibility = (): void => void loadRoundsOverlay();
    document.addEventListener('visibilitychange', onVisibility);

    return () => {
      cancelled = true;
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, []);

  // Toasts self-dismiss; anything durable belongs in the HUD or status bar.
  useEffect(() => {
    if (!toastMessage) return;
    const timer = window.setTimeout(() => setToastMessage(null), 6000);
    return () => window.clearTimeout(timer);
  }, [toastMessage]);

  // Handoff scope=unit:{id|abbr} → that unit's floor, once derivable.
  useEffect(() => {
    if (scopeAppliedRef.current || !handoff.unitRef) return;
    if (!Object.keys(locations).length) return;
    scopeAppliedRef.current = true;

    const ref = handoff.unitRef.toLowerCase();
    const unit = /^\d+$/.test(ref)
      ? units.find((candidate) => candidate.unit_id === Number(ref))
      : units.find((candidate) => candidate.unit_code?.toLowerCase() === ref);
    const floor = unit
      ? placementIndex.unitFloors.get(unit.unit_id)
      : Object.values(locations).find((loc) => loc.unit_code?.toLowerCase() === ref)?.floor;
    if (floor !== undefined && floor !== null) {
      setFilters((prev) => ({ ...prev, floor: String(floor) }));
    }
  }, [handoff.unitRef, locations, placementIndex, units]);

  // ---- three.js scene (lazy chunk) ----------------------------------------
  useEffect(() => {
    const canvas = canvasRef.current;
    const container = containerRef.current;
    if (!canvas || !container || !summary?.model_url) return;

    let disposed = false;
    let scene: NavigatorScene | null = null;

    void import('./NavigatorScene').then(({ NavigatorScene: SceneClass }) => {
      if (disposed) return;
      scene = new SceneClass(canvas, container, {
        onSelect: (data) => {
          const redacted = redactSelection(data, dotsPolicy);
          // E-5: panel and scene agree — the title carries the element type
          // the selection highlight is pointing at.
          const element = elementLabelFor(data);
          const name = String(
            redacted.patient_display_id ?? redacted.label ?? redacted.name ?? redacted.code
              ?? redacted.location_name ?? redacted.location ?? redacted.kind ?? 'Selected',
          );
          setInspectorTitle(element && element !== name ? `${element} · ${name}` : name);
          setInspectorRows(flattenInspector(redacted));
          // R-2: a round-stop selection links straight back to the board —
          // except under an aggregate lens (F-2: no patient-specific deep
          // link on a patient_dots=none wall).
          setInspectorAction(
            data.kind === 'round-stop' && typeof data.round_patient_uuid === 'string'
              && dotsPolicy !== 'none'
              ? { label: 'Open in Rounds board', href: `/rtdc/virtual-rounds?patient=${data.round_patient_uuid}` }
              : null,
          );
          // B1: a patient selection opens their journey; anything else closes
          // it so the inspector slot always describes the current selection.
          // (Phase C fixed the original kind test: token userData carries
          // 'patient-token', so a direct canvas click never opened the drawer
          // — only the list/deep-link paths did. A deviation-glyph click
          // routes to the SAME patient journey, where the adherence panel is.)
          if (data.kind === 'patient-token') {
            openJourneyRef.current(String(redacted.patient_context_ref ?? redacted.patient_id ?? ''));
          } else if (data.kind === 'pathway-deviation' && typeof data.patient_id === 'string') {
            openJourneyRef.current(data.patient_id);
          } else {
            closeJourneyRef.current();
          }
        },
        // The operator's hand always wins: camera input pauses the rounds
        // Auto tour AND patient follow (B3) in one gesture.
        onUserCameraStart: () => {
          setTourAuto(false);
          setJourneyFollow(false);
        },
        // E-4 hover chip: identity-free by construction AND by guard test —
        // hoverLabelFor lives in sceneVocabulary, pinned by hoverLabel.test.ts.
        hoverLabel: hoverLabelFor,
        onCameraMove: handleCameraMove,
        onFrame: (delta) => {
          if (!playingRef.current || liveRef.current) return;
          const next = currentTimeRef.current + delta * speedRef.current * 60 * 1000;
          const { start, end } = windowRef.current;
          const replayEnd = historicalRef.current ? end : Math.min(nowMsRef.current, end);
          const bounded = next > replayEnd ? start : next;
          currentTimeRef.current = bounded;
          const wallNow = performance.now();
          if (wallNow - lastTimeEmitRef.current > 150) {
            lastTimeEmitRef.current = wallNow;
            setCurrentTime(bounded);
          }
          refreshScene();
        },
      });
      sceneRef.current = scene;
      // Ordering guard: state-mirroring effects (trace/follow) that fired
      // while the lazy chunk was still loading saw a null sceneRef and
      // no-oped; bumping the nonce re-runs them against the live scene.
      // (Exposed by the ?patient= deep link on a slow first load.)
      setSceneNonce((nonce) => nonce + 1);
      // E5: a linked camera pose restores exactly once, before the model
      // finishes loading — the link IS the operator's framing.
      if (handoff.camera && !handoffCameraDoneRef.current) {
        handoffCameraDoneRef.current = true;
        scene.setCameraView(handoff.camera);
      }
      scene.setHoverEnabled(!(playingRef.current && speedRef.current > 60));
      lastBucketKeyRef.current = '';
      lastRoundsKeyRef.current = '';
      scene.loadModel(
        summary.model_url,
        () => {
          lastBucketKeyRef.current = '';
          lastRoundsKeyRef.current = '';
          refreshScene();
        },
        () => {
          setError('Model failed to load');
          setStatus('Model failed to load');
        },
      );
      refreshScene();
    });

    return () => {
      disposed = true;
      eventSourceRef.current?.close();
      sceneRef.current = null;
      scene?.dispose();
    };
  }, [summary?.model_url, dotsPolicy, handleCameraMove, refreshScene, handoff.camera]);

  // ---- playback / live -----------------------------------------------------
  const disconnectLive = useCallback((): void => {
    eventSourceRef.current?.close();
    eventSourceRef.current = null;
    setLive(false);
    setStatus(historical && dataEnd !== null
      ? `Historical - last event ${new Date(dataEnd).toLocaleString()}`
      : 'Stored event replay');
  }, [dataEnd, historical]);

  const connectLive = useCallback((): void => {
    if (!patientDotsVisible) {
      setStatus('Stored patient replay unavailable for this persona');
      return;
    }
    eventSourceRef.current?.close();
    const source = createPatientFlowEventSource({ replay: 180, interval: 0.65, persona: lens?.role_id });
    eventSourceRef.current = source;
    source.addEventListener('patient-flow', (message) => {
      const event = JSON.parse((message as MessageEvent<string>).data) as PatientFlowEvent;
      setEvents((prev) => {
        if (prev.some((item) => item.event_id === event.event_id)) return prev;
        return [...prev, event].sort((a, b) => parseTime(a.occurred_at) - parseTime(b.occurred_at));
      });
      // This endpoint replays stored rows. Do not label it live until a
      // cursor-backed connector feed exists.
      const eventTime = parseTime(event.occurred_at);
      applyTime(Math.min(windowEnd, Math.max(windowStart, eventTime)));
      setFeed((prev) => [event, ...prev.filter((item) => item.event_id !== event.event_id)].slice(0, 8));
    });
    source.onerror = () => setStatus('Stored replay reconnecting');
    setLive(true);
    setStatus('Streaming stored replay');
  }, [applyTime, patientDotsVisible, windowEnd, windowStart]);

  const handleScrub = useCallback((timeMs: number): void => {
    disconnectLive();
    const bounded = Math.min(windowEnd, Math.max(windowStart, timeMs));
    // Scrubbing to now (Now button, or landing on it) enters live-follow;
    // scrubbing anywhere else is a deliberate position that must never slide.
    followNowRef.current = Math.abs(bounded - nowMsRef.current) < 5_000;
    applyTime(bounded);
  }, [applyTime, disconnectLive, windowEnd, windowStart]);

  const resetCamera = useCallback((): void => {
    sceneRef.current?.resetCamera();
  }, []);

  const focusActivePatients = useCallback((): void => {
    const scene = sceneRef.current;
    if (!scene) return;
    scene.focusOn(lastVisibleStatesRef.current.map((state) => state.position));
  }, []);

  // E-4 perf guard: hover raycasts pause during fast playback.
  useEffect(() => {
    sceneRef.current?.setHoverEnabled(!(playing && speed > 60));
  }, [playing, speed]);

  // Escape is handled with the other shortcuts below (N-6) so the same
  // typing-context guard applies — Escape inside the Find field must clear
  // only the field, never the operator's selection.

  // B-4: the explicit camera action for a narrowed census scope — the
  // operator asks for the flight; the radio never causes it. Delayed flies
  // to the delayed disks; Deviations flies to the flagged patients' tokens.
  const focusCensusScope = useCallback((): void => {
    if (censusScopeRef.current === 'deviations') {
      const points = lastVisibleStatesRef.current
        .filter((state) => deviationsRef.current.has(state.patientId))
        .map((state) => state.position);
      if (points.length) sceneRef.current?.focusOn(points);
      return;
    }
    const points = lastOccupancyInsightsRef.current
      .filter(isBarrierOrDelay)
      .map((item) => item.position);
    sceneRef.current?.focusOn(points);
  }, []);

  // N-4: fit-to-floor — frame the selected floor's locations. Choosing All
  // widens the FILTER only; it never discards the operator's framing (Home
  // is the explicit reset).
  const fitToFloor = useCallback((floor: string): void => {
    const scene = sceneRef.current;
    if (!scene) return;
    if (floor === 'all') return;
    const points = Object.values(locationsRef.current)
      .filter((loc) => loc.position_m && String(loc.floor) === floor)
      .map((loc) => ({ x: loc.position_m!.x, y: loc.position_m!.y ?? 0, z: loc.position_m!.z }));
    scene.focusOn(points);
  }, []);

  // Explicit floor choices (rail or dropdown) filter AND frame; handoff-driven
  // filter writes deliberately do not move the camera.
  const handleFloorSelect = useCallback((floor: string): void => {
    setFilters((prev) => ({ ...prev, floor }));
    fitToFloor(floor);
  }, [fitToFloor]);

  // E2 canonical views (Top / House / Floor / Bed): the four framings an
  // operator reaches for, each a van Wijk arc. "Where am I / how do I get
  // back" in one click. Top/Floor frame the current floor's points (or the
  // whole house at 'all'); Bed frames the current selection.
  const applyCanonicalView = useCallback((view: 'top' | 'house' | 'floor' | 'bed'): void => {
    const scene = sceneRef.current;
    if (!scene) return;
    const floorFilter = filtersRef.current.floor;
    const floorPoints = (): Array<{ x: number; y: number; z: number }> =>
      Object.values(locationsRef.current)
        .filter((loc) => loc.position_m && (floorFilter === 'all' || String(loc.floor) === floorFilter))
        .map((loc) => ({ x: loc.position_m!.x, y: loc.position_m!.y ?? 0, z: loc.position_m!.z }));

    if (view === 'house') {
      scene.flyToHome();
    } else if (view === 'top') {
      scene.focusTopDown(floorPoints());
    } else if (view === 'floor') {
      scene.focusOn(floorPoints());
    } else if (!scene.focusTrace()) {
      // Bed: the selection (trace frames the whole story when a journey is
      // open); with nothing selected, fall back to the active patients.
      focusActivePatients();
    }
  }, [focusActivePatients]);

  // N-5: Enter in Find flies to the bbox of the matched tokens.
  const focusSearchMatches = useCallback((): void => {
    const points = lastVisibleStatesRef.current.map((state) => state.position);
    if (points.length) sceneRef.current?.focusOn(points);
  }, []);

  // H1.2: the non-pointer selection path — search list and feed rows select
  // exactly what a canvas click on that token would (same builder, same
  // redaction, same scene highlight via the selection entity).
  const closeJourney = useCallback((): void => {
    journeyPatientRef.current = null;
    setJourneyState('idle');
    setJourneyData(null);
    setJourneyFollow(false);
    setJourneyLinkCopied(false);
    setAdherence(null);
  }, []);

  // Phase C: the drawer's adherence read — cached verdicts for the opened
  // patient (same ptok scope + persona chain as the journey itself). A
  // forbidden read means the surface simply is not there for this persona;
  // errors render a quiet unavailability line, never a broken drawer.
  const loadAdherenceForPatient = useCallback((patientContextRef: string): void => {
    if (!conformanceEnabled) {
      setAdherence(null);
      return;
    }
    setAdherence({ state: 'loading', verdicts: [], asOf: null, cadenceMinutes: conformanceCadenceRef.current });
    void fetchCaseConformance({ patientContextRef, persona: lens?.role_id }).then((result) => {
      if (journeyPatientRef.current !== patientContextRef) return; // stale response
      if (result.kind === 'ok') {
        setAdherence({
          state: 'ok',
          verdicts: result.data.verdicts ?? [],
          asOf: result.data.computed_at ?? null,
          cadenceMinutes: conformanceCadenceRef.current,
        });
        return;
      }
      setAdherence(result.kind === 'forbidden'
        ? null
        : { state: 'error', verdicts: [], asOf: null, cadenceMinutes: conformanceCadenceRef.current });
    });
  }, [conformanceEnabled, lens?.role_id]);

  // Opens the journey for an opaque patient context ref. Persona rides the
  // request (F-1) and the patient is addressed only through the ptok scope —
  // the server's patientScope() runs the A2P authorization + audit.
  const openJourneyForPatient = useCallback((patientContextRef: string | null | undefined): void => {
    if (typeof patientContextRef !== 'string' || !/^ptok_[A-Za-z0-9]{24}$/.test(patientContextRef)) return;
    journeyPatientRef.current = patientContextRef;
    setJourneyData(null);
    setJourneyFollow(false);
    setJourneyLinkCopied(false);
    setJourneyState('loading');
    loadAdherenceForPatient(patientContextRef);

    void fetchPatientJourney({ patientContextRef, persona: lens?.role_id }).then((result) => {
      if (journeyPatientRef.current !== patientContextRef) return; // stale response
      if (result.kind === 'ok') {
        setJourneyData(result.journey);
        setJourneyState('ok');
        return;
      }
      // Fallback: the inspector still carries the scene detail (plan B: a
      // journey miss must never cost the operator the selection itself).
      journeyPatientRef.current = null;
      setJourneyState('idle');
      setAdherence(null);
      setStatus(result.kind === 'forbidden'
        ? 'Journey not available for this persona'
        : 'Journey unavailable — inspector fallback');
    });
  }, [lens?.role_id, loadAdherenceForPatient]);

  // C1: the governed exception-note draft — resolves true only when the note
  // landed as a PENDING approval on the Eddy plane (never auto-approved).
  const draftExceptionNote = useCallback(async (pathway: string, deviations: string[], note: string): Promise<boolean> => {
    const ptok = journeyPatientRef.current;
    if (!ptok) return false;
    const result = await postExceptionNote({
      patientContextRef: ptok,
      pathway,
      note,
      deviations,
      persona: lens?.role_id,
    });
    return result.kind === 'ok';
  }, [lens?.role_id]);

  // "Explain this deviation" (§7.2) — the AI plane, only when the copilot is
  // on. State-only prompt: pathway + worded deviation + computed evidence;
  // never an identifier (Eddy's phi policy is prompt-minimized regardless).
  const explainDeviation = useCallback((pathway: string, deviationLabel: string, evidence: string | null): void => {
    openEddyWithPrefill(
      `On the ${pathwayLabel(pathway)} care pathway, explain this observed deviation and what typically causes it operationally: "${deviationLabel}"${evidence ? ` (${evidence})` : ''}. This is from the 4D Navigator's conformance batch.`,
    );
  }, [openEddyWithPrefill]);

  // The scene's onSelect closure is constructed once at mount; route through
  // refs so it always calls the current opener/closer (the file-wide pattern).
  const openJourneyRef = useRef(openJourneyForPatient);
  const closeJourneyRef = useRef(closeJourney);
  useEffect(() => {
    openJourneyRef.current = openJourneyForPatient;
    closeJourneyRef.current = closeJourney;
  }, [closeJourney, openJourneyForPatient]);

  const copyJourneyLink = useCallback((): void => {
    const ptok = journeyPatientRef.current;
    if (!ptok) return;
    const url = `${window.location.origin}/rtdc/patient-flow-navigator?patient=${ptok}`;
    void navigator.clipboard?.writeText(url).then(() => {
      setJourneyLinkCopied(true);
      window.setTimeout(() => setJourneyLinkCopied(false), 2_000);
    }).catch(() => setStatus('Copy failed — clipboard unavailable'));
  }, []);

  // E5: snapshot the exact current view as a shareable URL. Selection rides
  // the strongest existing param for its kind (patient=ptok / focus_stop=
  // uuid / sel=aggregate) — never a new way to address a person.
  const buildCurrentViewUrl = useCallback((): string => {
    const scene = sceneRef.current;
    const entity = scene?.getSelectedEntity() ?? null;
    const snapshot: NavigatorViewSnapshot = {
      camera: scene ? scene.getCameraView() : null,
      floor: filtersRef.current.floor,
      layers: { ...layersRef.current },
      censusScope: censusScopeRef.current,
      timeMs: followNowRef.current ? null : currentTimeRef.current,
      windowPreset: windowPresetRef.current,
      selection: entity && (entity.kind === 'occupancy' || entity.kind === 'barrier')
        ? { kind: entity.kind, id: entity.id }
        : null,
      patient: entity?.kind === 'patient' && /^ptok_[A-Za-z0-9]{24}$/.test(entity.id) ? entity.id : null,
      focusStop: entity?.kind === 'round-stop' ? entity.id : null,
    };
    return `${window.location.origin}/rtdc/patient-flow-navigator${buildViewSearch(snapshot)}`;
  }, []);

  const copyViewLink = useCallback((): void => {
    void navigator.clipboard?.writeText(buildCurrentViewUrl())
      .then(() => setToastMessage('View link copied'))
      .catch(() => setStatus('Copy failed — clipboard unavailable'));
  }, [buildCurrentViewUrl]);

  // E5: a saved-view bookmark shares as a link too — camera/floor/layers only
  // (a bookmark has no time or selection of its own).
  const copySavedViewLink = useCallback((slot: number): void => {
    const view = views[slot];
    if (!view) return;
    const snapshot: NavigatorViewSnapshot = {
      camera: view.camera,
      floor: view.floor,
      layers: { ...layersRef.current, ...view.layers },
      censusScope: 'all',
      timeMs: null,
      windowPreset: '48h',
      selection: null,
      patient: null,
      focusStop: null,
    };
    const url = `${window.location.origin}/rtdc/patient-flow-navigator${buildViewSearch(snapshot)}`;
    void navigator.clipboard?.writeText(url)
      .then(() => setToastMessage(`View ${slot + 1} link copied`))
      .catch(() => setStatus('Copy failed — clipboard unavailable'));
  }, [views]);

  const selectPatientFromList = useCallback((patientId: string): void => {
    const state = lastVisibleStatesRef.current.find((candidate) => candidate.patientId === patientId);
    if (!state) return;
    const redactIdentity = dotsPolicy !== null && dotsPolicy !== 'full';
    const data = patientTokenInspectorData(state, redactIdentity);
    const redacted = redactSelection(data, dotsPolicy);
    const element = elementLabelFor(data);
    const name = String(redacted.patient_display_id ?? redacted.current_location ?? 'Patient');
    setInspectorTitle(element && element !== name ? `${element} · ${name}` : name);
    setInspectorRows(flattenInspector(redacted));
    setInspectorAction(null);
    sceneRef.current?.selectEntity({ kind: 'patient', id: patientId });
    openJourneyForPatient(String(redacted.patient_context_ref ?? redacted.patient_id ?? patientId));
  }, [dotsPolicy, openJourneyForPatient]);

  // F-8: keyboard/AT selection of a delayed location — the same code path a
  // canvas raycast on the disk takes (shared occupancyInspectorData builder,
  // shared selectEntity API). Labels are location-level, identity-safe.
  const selectLocationFromList = useCallback((location: string): void => {
    const insight = lastOccupancyInsightsRef.current.find((candidate) => candidate.location === location);
    if (!insight) return;
    const data = occupancyInspectorData(insight);
    const redacted = redactSelection(data, dotsPolicy);
    const element = elementLabelFor(data);
    const name = String(redacted.location_name ?? redacted.location ?? 'Location');
    setInspectorTitle(element && element !== name ? `${element} · ${name}` : name);
    setInspectorRows(flattenInspector(redacted));
    setInspectorAction(null);
    sceneRef.current?.selectEntity({ kind: 'occupancy', id: location });
  }, [dotsPolicy]);

  // F-8: keyboard/AT selection of an open barrier (aggregate, patient-free).
  const selectBarrierFromList = useCallback((barrierId: number): void => {
    const barrier = barriersRef.current.find((candidate) => candidate.barrier_id === barrierId);
    if (!barrier) return;
    const rows: Array<[string, string]> = [
      ['Category', barrier.category],
      ['Unit', barrier.unit_label ?? (barrier.unit_id !== null ? `Unit ${barrier.unit_id}` : '—')],
      ['Reason', barrier.reason_code ?? '—'],
      ['Owner', barrier.owner ?? '—'],
      ['Opened', barrier.opened_at ?? '—'],
    ];
    if (barrier.description) rows.push(['Detail', barrier.description]);
    setInspectorTitle(`Barrier · ${barrier.category}`);
    setInspectorRows(rows);
    setInspectorAction(null);
    sceneRef.current?.selectEntity({ kind: 'barrier', id: String(barrier.barrier_id) });
  }, []);

  // E5: restore a linked aggregate selection once its dataset is present —
  // the same list-selection code paths a keyboard user takes (H1.2/F-8).
  useEffect(() => {
    if (handoffSelectionDoneRef.current || !handoff.selection) return;
    const { kind, id } = handoff.selection;
    if (kind === 'occupancy') {
      if (!lastOccupancyInsightsRef.current.some((insight) => insight.location === id)) return;
      handoffSelectionDoneRef.current = true;
      selectLocationFromList(id);
    } else {
      const barrierId = Number(id);
      if (!Number.isFinite(barrierId) || !barriers.some((barrier) => barrier.barrier_id === barrierId)) return;
      handoffSelectionDoneRef.current = true;
      selectBarrierFromList(barrierId);
    }
    // The link is explicit locate intent (the ?patient=/?focus_stop
    // precedent) — but a linked camera pose outranks the flight.
    if (!handoff.camera) sceneRef.current?.focusSelection();
  }, [handoff.selection, handoff.camera, metrics, barriers, sceneNonce, selectBarrierFromList, selectLocationFromList]);

  // N-7: three persona-keyed camera/floor/layers bookmarks. The updater
  // stays pure — persistence happens outside setState.
  const saveView = useCallback((slot: number): void => {
    const scene = sceneRef.current;
    if (!scene) return;
    const next = [...views];
    next[slot] = {
      camera: scene.getCameraView(),
      floor: filtersRef.current.floor,
      layers: { ...layersRef.current },
    };
    setViews(next);
    try {
      window.localStorage.setItem(viewsStorageKey, serializeSavedViews(next));
    } catch {
      // Storage unavailable: the view still works for this session.
    }
  }, [views, viewsStorageKey]);

  const applyView = useCallback((slot: number): void => {
    const view = views[slot];
    if (!view) return;
    setFilters((prev) => ({ ...prev, floor: view.floor }));
    setLayers((prev) => mergeLayers(prev, view.layers));
    sceneRef.current?.setCameraView(view.camera);
  }, [views]);

  // N-6/E-5: the ONE window keymap — H home, F focus selection (else active
  // patients), N now, ? shortcut sheet, Escape clear selection + close
  // panels. Typing contexts are left alone for every key, Escape included
  // (inside Find, Escape clears only the field via the input's own handler).
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent): void => {
      const target = event.target as HTMLElement | null;
      const tag = target?.tagName;
      if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || target?.isContentEditable) return;
      if (event.metaKey || event.ctrlKey || event.altKey) return;
      if (event.key === 'Escape') {
        setShortcutsOpen(false);
        dismissIntro();
        sceneRef.current?.clearSelection();
        setInspectorTitle('Select a patient or location');
        setInspectorRows([]);
        setInspectorAction(null);
        closeJourney();
        return;
      }
      const key = event.key.toLowerCase();
      if (key === 'h') {
        setOrtho(false);
        sceneRef.current?.resetCamera();
      } else if (key === 'f') {
        // focusTrace frames the whole traced story when a journey is open
        // and falls back to the plain selection focus otherwise (B3).
        if (!sceneRef.current?.focusTrace()) focusActivePatients();
      } else if (key === 'n') {
        if (!historical) handleScrub(nowMsRef.current);
      } else if (key === 'o') {
        // E3: toggle the top-down orthographic plan view.
        const scene = sceneRef.current;
        if (scene) setOrtho(scene.setOrthographic(!scene.isOrthographic()));
      } else if (event.key === '?') {
        setShortcutsOpen((value) => !value);
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [closeJourney, dismissIntro, focusActivePatients, handleScrub, historical]);

  // E3: keep the ortho toggle in sync when Home/canonical views exit plan view
  // (resetCamera drops ortho; the toolbar toggle must reflect that).
  const toggleOrtho = useCallback((): void => {
    const scene = sceneRef.current;
    if (scene) setOrtho(scene.setOrthographic(!scene.isOrthographic()));
  }, []);

  // E2 — SDF unit-name billboards. Rebuilt only when the unit set, floor
  // filter, or Model layer changes (not per frame); the scene owns per-frame
  // billboarding, LOD, and distance culling. Tied to the Model layer — if you
  // can see the building you can read its unit names; far ones fade out.
  useEffect(() => {
    const scene = sceneRef.current;
    if (!scene) return;
    const floorFilter = filters.floor;
    const labels: Array<{ id: string; text: string; position: { x: number; y: number; z: number } }> = [];
    for (const [unitId, anchor] of placementIndex.unitAnchors) {
      const floor = placementIndex.unitFloors.get(unitId);
      if (floorFilter !== 'all' && String(floor) !== floorFilter) continue;
      const unit = units.find((candidate) => candidate.unit_id === unitId);
      const text = unit?.name
        ?? placementIndex.unitCodeById.get(unitId)?.toUpperCase()
        ?? `Unit ${unitId}`;
      labels.push({ id: String(unitId), text, position: anchor });
    }
    scene.setUnitLabels(labels, layers.base);
  }, [placementIndex, units, filters.floor, layers.base, sceneNonce]);

  // E4 — GSTC flatten on the 3D floor: the last 6 h of movement as flat density
  // tiles. Recomputed when toggled, the data, floor, or wall-now changes (a
  // cheap re-bin, not per frame). Off → the layer clears.
  useEffect(() => {
    const scene = sceneRef.current;
    if (!scene) return;
    if (!floorHeatOn) {
      scene.setTrailHeat([], 0, 0, false);
      return;
    }
    const bounds = heatBounds(locations, filters.floor);
    if (!bounds) {
      scene.setTrailHeat([], 0, 0, false);
      return;
    }
    const cols = 16;
    const rows = 16;
    const grid = densityGrid(tracks, locations, bounds, nowMs - 6 * 3_600_000, nowMs, cols, rows, filters.floor);
    const cells = gridToWorldCells(grid, bounds, 0.35);
    const cellW = (bounds.maxX - bounds.minX) / cols;
    const cellD = (bounds.maxZ - bounds.minZ) / rows;
    scene.setTrailHeat(cells, cellW, cellD, true);
  }, [floorHeatOn, tracks, locations, filters.floor, nowMs, sceneNonce]);

  // B3 — trace mode mirrors the journey drawer: open journey = traced story
  // in-scene (gradient trail + dwell markers, others dimmed). Toggling clears
  // the bucket key so the trail layer rebuilds immediately.
  useEffect(() => {
    const scene = sceneRef.current;
    if (!scene) return;
    scene.setTraceMode(journeyState === 'ok' ? journeyPatientRef.current : null);
    lastBucketKeyRef.current = '';
    refreshScene();
  }, [journeyData, journeyState, refreshScene, sceneNonce]);

  // B3 — follow-patient: explicit drawer toggle; operator camera input or
  // closing the journey clears it.
  useEffect(() => {
    sceneRef.current?.setFollowPatient(journeyFollow ? journeyPatientRef.current : null);
  }, [journeyData, journeyFollow, sceneNonce]);

  // B4 — the ?patient= cross-surface pivot (PJ-3): once events are loaded,
  // select the patient and open their journey (one attempt per mount; the
  // deep link is explicit locate intent, so the F flight is warranted —
  // same precedent as ?focus_stop). A miss reads honestly in the status bar.
  const handoffPatientDoneRef = useRef(false);
  useEffect(() => {
    if (handoffPatientDoneRef.current || !handoff.patient || events.length === 0) return;
    handoffPatientDoneRef.current = true;

    const matched = events.some((event) => event.patient_id === handoff.patient);
    if (!matched) {
      setStatus('Linked patient not in the current window');
      return;
    }

    sceneRef.current?.selectEntity({ kind: 'patient', id: handoff.patient });
    openJourneyForPatient(handoff.patient);
    if (!sceneRef.current?.focusSelection()) focusActivePatients();
  }, [events, focusActivePatients, handoff.patient, openJourneyForPatient]);

  // ---- Virtual Rounds integration (Phase 3) --------------------------------

  // R-1: focus a round stop once its ring is actually placed. The scene and
  // the stops load independently, so retry on a short interval; if the stop
  // is unplaceable, clear the floor filter once and retry, then fall back to
  // a toast pointing at the board.
  const requestStopFocus = useCallback((uuid: string): void => {
    pendingFocusStopRef.current = uuid;
    focusFloorClearedRef.current = false;
    setFocusTick((value) => value + 1);
  }, []);

  useEffect(() => {
    const uuid = pendingFocusStopRef.current;
    if (!uuid || roundStops.length === 0) return;
    // Focusing a stop implies wanting to SEE the rounds layer.
    if (!layersRef.current.rounds) {
      setLayers((prev) => (prev.rounds ? prev : { ...prev, rounds: true }));
    }
    focusAttemptsRef.current = 0;
    const timer = window.setInterval(() => {
      const scene = sceneRef.current;
      // Scene/model still loading: wait without burning the attempt budget —
      // the budget measures real placement failures, not load time.
      if (!scene) return;
      if (scene.focusRoundStop(uuid)) {
        pendingFocusStopRef.current = null;
        window.clearInterval(timer);
        return;
      }
      focusAttemptsRef.current += 1;
      if (!focusFloorClearedRef.current) {
        focusFloorClearedRef.current = true;
        setFilters((prev) => (prev.floor === 'all' ? prev : { ...prev, floor: 'all' }));
        return;
      }
      if (focusAttemptsRef.current >= 15) {
        pendingFocusStopRef.current = null;
        window.clearInterval(timer);
        scene.focusRoundStop(null);
        setToastMessage('Stop not placeable — open the Rounds board');
      }
    }, 600);
    return () => window.clearInterval(timer);
  }, [roundStops, focusTick]);

  // R-6a: manual tour — queue order among placeable, walkable stops.
  const tourStops = useMemo(
    () => buildRoundStopCells(roundStops, placementIndex, 'all')
      .filter((cell) => !['skipped', 'deferred'].includes(cell.stop.status))
      .sort((a, b) => a.stop.queue_position - b.stop.queue_position),
    [placementIndex, roundStops],
  );

  const showStopInspector = useCallback((stop: RoundStop): void => {
    const data: Record<string, unknown> = {
      kind: 'round-stop',
      round_patient_uuid: stop.round_patient_uuid,
      status: stop.status,
      queue_position: stop.queue_position,
      priority_band: stop.priority_band,
      ...(stop.bed ? { bed: stop.bed } : {}),
      ...(stop.pinned ? { pinned: true } : {}),
      ...(stop.discharge_ready ? { discharge_ready: true } : {}),
      ...(stop.missing_input ? { missing_input: true } : {}),
    };
    setInspectorTitle(`Round stop · #${stop.queue_position}`);
    setInspectorRows(flattenInspector(redactSelection(data, dotsPolicy)));
    // F-2: no patient-specific deep link under an aggregate lens.
    setInspectorAction(dotsPolicy !== 'none'
      ? { label: 'Open in Rounds board', href: `/rtdc/virtual-rounds?patient=${stop.round_patient_uuid}` }
      : null);
  }, [dotsPolicy]);

  const tourStep = useCallback((direction: 1 | -1): void => {
    if (tourStops.length === 0) return;
    // Anchor by uuid — the stops list reorders/shrinks on every poll, so a
    // raw index would silently skip or repeat a bed after a queue change.
    const anchored = tourUuidRef.current
      ? tourStops.findIndex((cell) => cell.stop.round_patient_uuid === tourUuidRef.current)
      : -1;
    const base = anchored !== -1
      ? anchored
      : (tourIndexRef.current ?? (direction === 1 ? -1 : tourStops.length));
    const next = Math.min(tourStops.length - 1, Math.max(0, base + direction));
    tourIndexRef.current = next;
    tourUuidRef.current = tourStops[next].stop.round_patient_uuid;
    const cell = tourStops[next];
    requestStopFocus(cell.stop.round_patient_uuid);
    showStopInspector(cell.stop);
    // H1.3: a tour step IS a selection — panel, highlight, F, and Escape all
    // point at the same stop.
    sceneRef.current?.selectEntity({ kind: 'round-stop', id: cell.stop.round_patient_uuid });
  }, [requestStopFocus, showStopInspector, tourStops]);

  const tourStopsRef = useRef(tourStops);
  useEffect(() => {
    tourStopsRef.current = tourStops;
    tourStepRef.current = tourStep;
    tourAutoRef.current = tourAuto;
  }, [tourAuto, tourStep, tourStops]);

  // State updaters must stay pure — the first-step side effect runs outside.
  const toggleTourAuto = useCallback((): void => {
    const enabling = !tourAutoRef.current;
    tourAutoRef.current = enabling;
    setTourAuto(enabling);
    if (enabling && tourUuidRef.current === null) tourStepRef.current(1);
  }, []);

  // Auto mode: 10 s dwell, stops at the end of the itinerary; any operator
  // camera input pauses it (wired via the scene's onUserCameraStart). The
  // interval reads refs so a 30 s poll delta never resets the dwell.
  useEffect(() => {
    if (!tourAuto) return;
    const timer = window.setInterval(() => {
      const stops = tourStopsRef.current;
      const index = tourIndexRef.current;
      if (index !== null && index >= stops.length - 1) {
        setTourAuto(false);
        return;
      }
      tourStepRef.current(1);
    }, 10_000);
    return () => window.clearInterval(timer);
  }, [tourAuto]);

  // R-5: run HUD — status, progress, and awaiting-input, straight from the
  // opaque stops (never coral; a round state is work, not a breach).
  const roundsHud = useMemo(() => {
    if (!roundsRun || roundStops.length === 0) return null;
    return {
      status: roundsRun.status,
      scopeLabel: roundsRun.scope_label,
      total: roundStops.length,
      rounded: roundStops.filter((stop) => stop.status === 'rounded').length,
      awaitingInput: roundStops.filter((stop) => stop.status === 'awaiting_input').length,
    };
  }, [roundsRun, roundStops]);

  const askEddy = useCallback((): void => {
    const serviceLines = occupancy.serviceLines.length > 0
      ? occupancy.serviceLines
          .map((item) => `${item.serviceLine}: ${item.occupied} occupied, ${item.delayed} delayed, ${item.watch} watch`)
          .join('; ')
      : 'No active service-line occupancy in the current lens.';
    const topBarriers = occupancy.topBarriers?.length
      ? occupancy.topBarriers
          .slice(0, 5)
          .map((item) => {
            const code = item.barrierCode ? `${item.barrierCode} / ` : '';
            const metrics = item.rtdcMetrics?.length ? ` Metrics: ${item.rtdcMetrics.join(', ')}.` : '';
            const focus = item.recommendedFocus ? ` Focus: ${item.recommendedFocus}` : '';
            return `${code}${item.label} (${item.count}): ${item.eddySummary ?? item.reason ?? item.ownerRole ?? 'active barrier'}.${metrics}${focus}`;
          })
          .join('; ')
      : 'No active barrier reasons in the current lens.';
    const sampleDiskDetails = lastOccupancyInsightsRef.current
      .filter((item) => item.primaryStatus !== 'ok')
      .slice(0, 4)
      .map((item) => {
        const reasons = item.barrierReasons?.length ? item.barrierReasons.join(' / ') : item.blockers.join(', ');
        const codes = item.barrierCodes?.length ? ` codes ${item.barrierCodes.join(', ')};` : '';
        return `${item.locationName ?? item.location}: ${item.serviceLine ?? 'unassigned'}; ${formatDurationMinutes(item.stayMinutes)} stay;${codes} ${reasons}`;
      })
      .join('; ') || 'No delayed disk details selected.';
    // The composer prefill is OPERATOR-readable evidence only (HFE audit
    // EDDY-02): no serialized context dumps, no prompt-engineering
    // instructions. The governed structured context still reaches Eddy
    // server-side through the chat request's page_context.
    openEddyWithPrefill(
      [
        'Review this Patient Flow 4D timer picture for RTDC demand-capacity risk.',
        `Persona lens: ${lens?.role_id ?? 'house'}. Floor filter: ${filtersRef.current.floor}. Service filter: ${filtersRef.current.serviceLine}.`,
        `Occupancy: ${occupancy.active} active, ${occupancy.delayed} delayed, ${occupancy.watch} watch, ${occupancy.readyToMove} ready inside ${formatDurationMinutes(30)}.`,
        `Timer blockers: ${occupancy.transportDelays} transport, ${occupancy.evsDelays} EVS, average stay ${formatDurationMinutes(occupancy.avgStayMinutes)}.`,
        `Service-line compounding: ${serviceLines}`,
        `Barrier reasons: ${topBarriers}`,
        `Disk examples: ${sampleDiskDetails}`,
        'What should this persona act on first, and where is cross-service-line compounding building?',
      ].join('\n'),
      'patient-flow-4d-timers',
    );
  }, [lens, occupancy, openEddyWithPrefill]);

  return (
    <section ref={containerRef} className="patient-flow-shell" aria-label="Patient Flow 4D Navigator">
      <canvas ref={canvasRef} className="patient-flow-canvas" aria-label="Patient flow 3D navigator" />

      {error && (
        <div className="patient-flow-error" role="alert">
          <span>{error}</span>
          <button
            type="button"
            className="patient-flow-error-retry"
            onClick={() => requestRebootstrap('Retrying load')}
          >
            Retry
          </button>
        </div>
      )}
      {rebuildNotice && !error && (
        <div className="patient-flow-rebuild-notice" role="status">{rebuildNotice}</div>
      )}

      <NavigatorToolbar
        summary={summary}
        ambient={ambient}
        lensTitle={lens ? lens.role_id.replaceAll('_', ' ') : null}
        chronobar={(
          <NavigatorChronobar
            windowStart={windowStart}
            windowEnd={windowEnd}
            nowMs={nowMs}
            currentTime={currentTime}
            dataStart={dataStart}
            dataEnd={dataEnd}
            historical={historical}
            freshness={summary?.source.freshness ?? 'missing'}
            forecast={forecast}
            barrierTicks={barrierTicks}
            densityBuckets={chronobarDensity}
            patientTicks={chronobarPatientTicks}
            replaying={live}
            onScrub={handleScrub}
            windowPreset={windowPreset}
            onWindowPresetChange={applyWindowPreset}
          />
        )}
        playing={playing}
        live={live}
        speed={speed}
        filters={filters}
        floors={floors}
        services={services}
        categories={categories}
        layers={layers}
        layerControls={layerControls}
        censusScope={censusScope}
        showDeviationScope={conformanceEnabled}
        metrics={metrics}
        occupancy={occupancy}
        eddyEnabled={eddyEnabled}
        onTogglePlay={() => {
          if (!playing) disconnectLive();
          setPlaying((value) => !value);
        }}
        onToggleLive={() => (live ? disconnectLive() : connectLive())}
        onResetCamera={() => { setOrtho(false); resetCamera(); }}
        onFocusPatients={focusActivePatients}
        onCanonicalView={applyCanonicalView}
        orthoActive={ortho}
        onToggleOrtho={toggleOrtho}
        onFocusCensusScope={focusCensusScope}
        onSpeedChange={setSpeed}
        onFiltersChange={(patch) => setFilters((prev) => ({ ...prev, ...patch }))}
        onFloorSelect={handleFloorSelect}
        onLayerChange={(key, value) => setLayers((prev) => ({ ...prev, [key]: value }))}
        onCensusScopeChange={setCensusScope}
        onAskEddy={askEddy}
        searchMatches={searchMatches}
        searchResults={searchResults}
        onSelectSearchResult={selectPatientFromList}
        onSearchSubmit={focusSearchMatches}
        savedViews={views.map((view) => view !== null)}
        onSaveView={saveView}
        onApplyView={applyView}
        onCopyViewLink={copyViewLink}
        onCopySavedViewLink={copySavedViewLink}
        roundsHud={roundsHud}
        tourAuto={tourAuto}
        onTourPrev={() => tourStep(-1)}
        onTourNext={() => tourStep(1)}
        onTourAutoToggle={toggleTourAuto}
      />

      <NavigatorFeed
        feed={feed}
        redactIdentity={dotsPolicy !== null && dotsPolicy !== 'full'}
        onSelectPatient={patientDotsVisible ? selectPatientFromList : undefined}
      />

      <NavigatorActionList
        delayed={actionableInsights}
        barriers={layers.barriers ? barriers : []}
        onSelectLocation={selectLocationFromList}
        onSelectBarrier={selectBarrierFromList}
      />

      <NavigatorStructureNav
        locations={locations}
        onSelectBed={selectLocationFromList}
        onFrame={(points) => sceneRef.current?.focusOn(points)}
      />

      {/* E1: the minimap answers "where am I" — suppressed on wall/kiosk
          (?wall=1), where the whole screen is the instrument. */}
      {!handoff.wall && (
        <NavigatorMinimap
          locations={locations}
          floor={filters.floor}
          delayed={actionableInsights}
          deviationLocations={deviationLocationSet}
          getCameraView={() => cameraViewRef.current}
          getSelectionPoint={() => sceneRef.current?.getSelectionPoint() ?? null}
          onNavigate={(point) => sceneRef.current?.focusOn([point])}
          onFitFloor={() => applyCanonicalView('floor')}
        />
      )}

      {/* E4: the hourly small-multiples analysis card — the non-replay path to
          "what happened this shift". Desk affordance; hidden on wall/kiosk. */}
      {!handoff.wall && patientDotsVisible && (
        <NavigatorSmallMultiples
          tracks={tracks}
          locations={locations}
          floor={filters.floor}
          endMs={nowMs}
          floorHeatOn={floorHeatOn}
          onToggleFloorHeat={() => setFloorHeatOn((value) => !value)}
        />
      )}

      {journeyState === 'idle' ? (
        <NavigatorInspector title={inspectorTitle} rows={inspectorRows} action={inspectorAction} />
      ) : (
        <NavigatorJourneyDrawer
          journey={journeyData}
          state={journeyState}
          align={journeyAlign}
          onAlignChange={setJourneyAlign}
          onClose={closeJourney}
          onFocus={() => {
            if (!sceneRef.current?.focusTrace()) focusActivePatients();
          }}
          followEnabled={journeyFollow}
          onFollowToggle={setJourneyFollow}
          onCopyLink={copyJourneyLink}
          copiedLink={journeyLinkCopied}
          adherence={adherence}
          onExceptionNote={conformanceEnabled ? draftExceptionNote : undefined}
          onExplainDeviation={conformanceEnabled && arenaAiEnabled && eddyEnabled ? explainDeviation : undefined}
        />
      )}

      <NavigatorLegend layers={layers} showPathways={conformanceEnabled} />

      {toastMessage && (
        <div className="patient-flow-toast" role="status">{toastMessage}</div>
      )}

      <NavigatorFloorRail floors={floors} current={filters.floor} onSelect={handleFloorSelect} />

      {shortcutsOpen && (
        <div
          className="patient-flow-shortcut-sheet"
          role="dialog"
          aria-label="Keyboard shortcuts"
          tabIndex={-1}
          ref={(node) => node?.focus()}
        >
          <strong>Keyboard shortcuts</strong>
          <dl>
            <div><dt>H</dt><dd>Home view</dd></div>
            <div><dt>F</dt><dd>Focus selection (or active patients)</dd></div>
            <div><dt>N</dt><dd>Jump to now</dd></div>
            <div><dt>O</dt><dd>Top-down plan view (orthographic)</dd></div>
            <div><dt>↑ ↓</dt><dd>Step floors (floor rail focused)</dd></div>
            <div><dt>← → ↑ ↓</dt><dd>Pan the camera (click the scene first)</dd></div>
            <div><dt>Enter</dt><dd>In Find: fly to matches</dd></div>
            <div><dt>Esc</dt><dd>Clear selection · close panels</dd></div>
            <div><dt>?</dt><dd>Toggle this sheet</dd></div>
          </dl>
          <div className="patient-flow-shortcut-actions">
            <button
              type="button"
              className="patient-flow-shortcut-close"
              onClick={() => {
                setShortcutsOpen(false);
                setIntroIndex(0);
                setIntroOpen(true);
              }}
            >
              Replay intro
            </button>
            <button
              type="button"
              className="patient-flow-shortcut-close"
              onClick={() => setShortcutsOpen(false)}
            >
              Close
            </button>
          </div>
        </div>
      )}

      {introOpen && (
        <NavigatorIntro
          stops={introStopList}
          index={introIndex}
          onIndexChange={setIntroIndex}
          onDismiss={dismissIntro}
        />
      )}

      <div className="patient-flow-statusbar">
        <span>{status}</span>
        <span>{ambient ? `Ambient ${Math.round(ambient.summary.averageConfidence * 100)}% ${ambient.summary.confidenceLevel}` : 'Ambient pending'}</span>
        <span ref={cameraSpanRef} className="patient-flow-camera">{cameraPlace}</span>
      </div>
    </section>
  );
}
