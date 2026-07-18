import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import {
  createPatientFlowEventSource,
  fetchPatientFlowAmbient,
  fetchPatientFlowBarriers,
  fetchPatientFlowEvents,
  fetchPatientFlowLocations,
  fetchPatientFlowOccupancy,
  fetchPatientFlowProjections,
  fetchPatientFlowSummary,
} from '@/features/patientFlowNavigator/api';
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
} from '@/features/patientFlowNavigator/replayTimeline';
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
import { buildRoundStopCells } from '@/features/virtualRounds/roundsScene';
import type { RoundStop } from '@/features/virtualRounds/roundsScene';
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
import { occupancyInspectorData } from '@/features/patientFlowNavigator/inspector';
import type { PageProps } from '@/types';
import type { CameraView, NavigatorScene } from './NavigatorScene';
import NavigatorChronobar from './NavigatorChronobar';
import NavigatorFeed from './NavigatorFeed';
import NavigatorInspector from './NavigatorInspector';
import NavigatorToolbar from './NavigatorToolbar';
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

interface HandoffParams {
  floor: string | null;
  unitRef: string | null;
  t: number | null;
}

/** Mobile→web A3 handoff: ?persona=&scope=&t= (persona is resolved server-side). */
function parseHandoff(): HandoffParams {
  const empty: HandoffParams = { floor: null, unitRef: null, t: null };
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

  return { floor, unitRef, t };
}

function defaultLayersForLens(lens: FlowLens | null | undefined): PatientLayerState {
  if (!lens) {
    return { base: true, tokens: true, trails: true, heat: true, ghosts: true, barriers: true, rounds: true };
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
  };
}

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
  const layersRef = useRef<PatientLayerState>(defaultLayersForLens(lens));
  const barrierFinderRef = useRef(false);
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
  const lastTimeEmitRef = useRef(0);
  const scopeAppliedRef = useRef(false);
  const inspectorInitializedRef = useRef(false);
  const occupancyRequestRef = useRef(0);

  const [summary, setSummary] = useState<PatientFlowSummary | null>(null);
  const [ambient, setAmbient] = useState<PatientFlowAmbient | null>(null);
  const [locations, setLocations] = useState<PatientFlowLocations>({});
  const [events, setEvents] = useState<PatientFlowEvent[]>([]);
  const [projections, setProjections] = useState<ProjectionItem[]>([]);
  const [barriers, setBarriers] = useState<NavigatorBarrier[]>([]);
  const [roundStops, setRoundStops] = useState<RoundStop[]>([]);
  const [filters, setFilters] = useState<PatientFlowFilters>(filtersRef.current);
  const [layers, setLayers] = useState<PatientLayerState>(layersRef.current);
  const [barrierFinder, setBarrierFinder] = useState(false);
  const [currentTime, setCurrentTime] = useState(currentTimeRef.current);
  const [speed, setSpeed] = useState(60);
  const [playing, setPlaying] = useState(false);
  const [live, setLive] = useState(false);
  const [status, setStatus] = useState('Loading');
  const [cameraPlace, setCameraPlace] = useState('');
  const [cameraDebug, setCameraDebug] = useState('');
  const [metrics, setMetrics] = useState<NavigatorMetrics>({ active: 0, events: 0, occupiedLocations: 0 });
  const [occupancy, setOccupancy] = useState<OccupancySummary>(EMPTY_OCCUPANCY_SUMMARY);
  const [forecast, setForecast] = useState<ForecastAggregates | null>(null);
  const [inspectorTitle, setInspectorTitle] = useState('Select a patient or location');
  const [inspectorRows, setInspectorRows] = useState<Array<[string, string]>>([]);
  const [feed, setFeed] = useState<PatientFlowEvent[]>([]);
  const [error, setError] = useState<string | null>(null);

  const tracks = useMemo(() => rebuildTracks(events), [events]);

  // S-1: advance wall-clock now every 60s. The ref updates in the same tick so
  // any repaint that fires before the effects run already sees the fresh value.
  useEffect(() => {
    const id = window.setInterval(() => {
      nowMsRef.current = Date.now();
      setNowMs(nowMsRef.current);
    }, 60_000);
    return () => window.clearInterval(id);
  }, []);

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
    return controls;
  }, [lens, patientDotsVisible, roundStops.length]);

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
    const visibleOccupancyInsights = barrierFinderRef.current
      ? occupancyInsights.filter(isBarrierOrDelay)
      : occupancyInsights;
    lastVisibleStatesRef.current = states;
    lastOccupancyInsightsRef.current = occupancyInsights;
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
      barrierFinderRef.current ? 'barriers' : 'all',
      eventsRef.current.length,
      projectionsRef.current.length,
      barriersRef.current.length,
      roundStopsRef.current.length,
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
    // position, floor-filtered, opaque tokens only (plan §8.1).
    const roundCells = layersRef.current.rounds
      ? buildRoundStopCells(roundStopsRef.current, index, floorFilter)
      : [];
    scene.rebuildRounds(roundCells, layersRef.current.rounds);

    setMetrics({
      active: barrierFinderRef.current ? visibleOccupancyInsights.length : (useServerOccupancy ? occupancySummary.active : states.length),
      events: eventsRef.current.filter((event) => parseTime(event.occurred_at) <= timeMs).length,
      occupiedLocations: occupied
        || new Set((barrierFinderRef.current || useServerOccupancy ? visibleOccupancyInsights.map((item) => item.location) : states.map((state) => state.event.to_location)).filter(Boolean)).size,
    });
  }, [dotsPolicy, lens, patientDotsVisible]);

  // Keep refs in sync with state, then repaint.
  useEffect(() => {
    eventsRef.current = events;
    locationsRef.current = locations;
    filtersRef.current = filters;
    layersRef.current = layers;
    barrierFinderRef.current = barrierFinder;
    projectionsRef.current = projections;
    barriersRef.current = barriers;
    roundStopsRef.current = roundStops;
    speedRef.current = speed;
    playingRef.current = playing;
    liveRef.current = live;
    tracksRef.current = tracks;
    placementIndexRef.current = placementIndex;
    refreshScene();
  }, [events, locations, filters, layers, barrierFinder, projections, barriers, roundStops, speed, playing, live, tracks, placementIndex, refreshScene]);

  // B-4: no camera side effect here — flying to the delayed set is an explicit
  // "Focus" action on the filter chip, never a consequence of toggling scope.
  useEffect(() => {
    barrierFinderRef.current = barrierFinder;
    lastBucketKeyRef.current = '';
    refreshScene();
  }, [barrierFinder, refreshScene]);

  // S-1: repaint when wall-clock now advances (the now-minute is part of the
  // heavy-layer bucket key, so severity and gating rebuild with the fresh now).
  useEffect(() => {
    nowMsRef.current = nowMs;
    refreshScene();
  }, [nowMs, refreshScene]);

  // Live-follow: slide the 48h window with wall-clock now, but only when the
  // operator is parked at now — a deliberate scrub position is never yanked,
  // and a playback sweep passing near now is not "parked".
  useEffect(() => {
    if (historical || playingRef.current) return;
    if (Math.abs(currentTimeRef.current - nowMs) >= 90_000) return;
    setTimeWindow({ start: nowMs - LIVE_WINDOW_HALF_MS, end: nowMs + LIVE_WINDOW_HALF_MS });
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

  // N-3: the camera readout speaks place, not xyz — the nearest unit centroid
  // to the orbit target names what the operator is looking at. Raw coordinates
  // survive in the status-bar title attribute for debugging.
  const handleCameraMove = useCallback((view: CameraView): void => {
    setCameraDebug(
      `camera x ${Math.round(view.position.x)} y ${Math.round(view.position.y)} z ${Math.round(view.position.z)}`
      + ` · target x ${Math.round(view.target.x)} z ${Math.round(view.target.z)}`,
    );

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
        const [summaryData, locationData, eventData, ambientData] = await Promise.all([
          fetchPatientFlowSummary(),
          fetchPatientFlowLocations(),
          patientDotsVisible ? fetchPatientFlowEvents({ limit: 20000 }) : Promise.resolve([]),
          fetchPatientFlowAmbient(),
        ]);
        if (cancelled) return;

        const prepared = prepareReplay(summaryData, eventData, mountedAtMs, handoff.t);
        const { events: sortedEvents, timeline } = prepared;
        setSummary(summaryData);
        setAmbient(ambientData);
        setLocations(locationData);
        setEvents(sortedEvents);
        setFeed(recentReplayEvents(sortedEvents));
        setTimeWindow({ start: timeline.windowStart, end: timeline.windowEnd });
        applyTime(timeline.currentTime);
        if (!patientDotsVisible) {
          setStatus('Aggregate persona lens');
        } else {
          setStatus(replayStatus(timeline));
        }
      } catch (caught) {
        const message = caught instanceof Error ? caught.message : 'Unable to load patient flow data';
        setError(message);
        setStatus('Load failed');
      }
    }

    void bootstrap();

    return () => {
      cancelled = true;
    };
  }, [applyTime, handoff.t, mountedAtMs, patientDotsVisible]);

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

  // Virtual Rounds overlay (plan §8.1) — the most recent open run's scene
  // stops, opaque tokens only. Feature flag off (404), no run, or any failure
  // simply leaves the overlay empty; the navigator never degrades.
  useEffect(() => {
    let cancelled = false;

    async function loadRoundsOverlay(): Promise<void> {
      try {
        const runsPayload = runsResponseSchema.safeParse(await fetchRoundRuns());
        if (!runsPayload.success || cancelled) return;

        const openRun = runsPayload.data.data.find((run) =>
          ['active', 'paused', 'draft', 'scheduled'].includes(run.status),
        );
        if (!openRun) return;

        const scenePayload = sceneResponseSchema.safeParse(await fetchRoundScene(openRun.run_uuid));
        if (!scenePayload.success || cancelled) return;

        setRoundStops(scenePayload.data.data.stops);
      } catch {
        if (!cancelled) setRoundStops([]);
      }
    }

    void loadRoundsOverlay();

    return () => {
      cancelled = true;
    };
  }, []);

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
          setInspectorTitle(String(
            redacted.patient_display_id ?? redacted.label ?? redacted.name ?? redacted.code ?? redacted.kind ?? 'Selected',
          ));
          setInspectorRows(flattenInspector(redacted));
        },
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
      lastBucketKeyRef.current = '';
      scene.loadModel(
        summary.model_url,
        () => {
          lastBucketKeyRef.current = '';
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
  }, [summary?.model_url, dotsPolicy, handleCameraMove, refreshScene]);

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
    const source = createPatientFlowEventSource({ replay: 180, interval: 0.65 });
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
    applyTime(Math.min(windowEnd, Math.max(windowStart, timeMs)));
  }, [applyTime, disconnectLive, windowEnd, windowStart]);

  const resetCamera = useCallback((): void => {
    sceneRef.current?.resetCamera();
  }, []);

  const focusActivePatients = useCallback((): void => {
    const scene = sceneRef.current;
    if (!scene) return;
    scene.focusOn(lastVisibleStatesRef.current.map((state) => state.position));
  }, []);

  // B-4: the explicit camera action for the Delayed-only census scope — the
  // operator asks for the flight; the checkbox never causes it.
  const focusDelayed = useCallback((): void => {
    const points = lastOccupancyInsightsRef.current
      .filter(isBarrierOrDelay)
      .map((item) => item.position);
    sceneRef.current?.focusOn(points);
  }, []);

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

      {error && <div className="patient-flow-error">{error}</div>}

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
            replaying={live}
            onScrub={handleScrub}
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
        barrierFinder={barrierFinder}
        metrics={metrics}
        occupancy={occupancy}
        eddyEnabled={eddyEnabled}
        onTogglePlay={() => {
          if (!playing) disconnectLive();
          setPlaying((value) => !value);
        }}
        onToggleLive={() => (live ? disconnectLive() : connectLive())}
        onResetCamera={resetCamera}
        onFocusPatients={focusActivePatients}
        onFocusDelayed={focusDelayed}
        onSpeedChange={setSpeed}
        onFiltersChange={(patch) => setFilters((prev) => ({ ...prev, ...patch }))}
        onLayerChange={(key, value) => setLayers((prev) => ({ ...prev, [key]: value }))}
        onBarrierFinderChange={setBarrierFinder}
        onAskEddy={askEddy}
      />

      <NavigatorFeed feed={feed} redactIdentity={dotsPolicy !== null && dotsPolicy !== 'full'} />

      <NavigatorInspector title={inspectorTitle} rows={inspectorRows} />

      <div className="patient-flow-statusbar">
        <span>{status}</span>
        <span>{ambient ? `Ambient ${Math.round(ambient.summary.averageConfidence * 100)}% ${ambient.summary.confidenceLevel}` : 'Ambient pending'}</span>
        <span className="patient-flow-camera" title={cameraDebug}>{cameraPlace}</span>
      </div>
    </section>
  );
}
