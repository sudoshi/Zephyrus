import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  createPatientFlowEventSource,
  fetchPatientFlowAmbient,
  fetchPatientFlowBarriers,
  fetchPatientFlowEvents,
  fetchPatientFlowLocations,
  fetchPatientFlowProjections,
  fetchPatientFlowSummary,
} from '@/features/patientFlowNavigator/api';
import {
  parseTime,
  patientStatesAt,
  rebuildTracks,
} from '@/features/patientFlowNavigator/stateProjection';
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
import type {
  FlowLens,
  FlowPatientDots,
  FlowUnitSummary,
  NavigatorBarrier,
  PatientFlowAmbient,
  PatientFlowEvent,
  PatientFlowFilters,
  PatientFlowLocations,
  PatientFlowSummary,
  PatientLayerState,
  PatientVisibleState,
  ProjectionItem,
} from '@/features/patientFlowNavigator/types';
import type { NavigatorScene } from './NavigatorScene';
import NavigatorChronobar from './NavigatorChronobar';
import NavigatorFeed from './NavigatorFeed';
import NavigatorInspector from './NavigatorInspector';
import NavigatorToolbar from './NavigatorToolbar';
import type { LayerControl, NavigatorMetrics } from './NavigatorToolbar';
import './PatientFlowNavigator.css';

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

const WINDOW_HALF_MS = 24 * 60 * 60 * 1000;

const IDENTITY_KEYS = ['patient_display_id', 'patient_id', 'encounter_id'] as const;

interface HandoffParams {
  floor: string | null;
  unitRef: string | null;
  t: number | null;
}

/** Mobile→web A3 handoff: ?persona=&scope=&t= (persona is resolved server-side). */
function parseHandoff(nowMs: number): HandoffParams {
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
    if (Number.isFinite(parsed)) {
      t = Math.min(nowMs + WINDOW_HALF_MS, Math.max(nowMs - WINDOW_HALF_MS, parsed));
    }
  }

  return { floor, unitRef, t };
}

function defaultLayersForLens(lens: FlowLens | null | undefined): PatientLayerState {
  if (!lens) {
    return { base: true, tokens: true, trails: true, heat: true, ghosts: true, barriers: true };
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

function flattenInspector(data: Record<string, unknown>): Array<[string, string]> {
  return Object.entries(data)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .slice(0, 18)
    .map(([key, value]) => [
      key.replaceAll('_', ' '),
      typeof value === 'object' ? JSON.stringify(value) : String(value),
    ]);
}

export default function PatientFlowNavigator({
  initialFloor = 'all',
  initialCategory = 'all',
  initialServiceLine = 'all',
  lens = null,
  units = [],
}: PatientFlowNavigatorProps) {
  // ---- 48h time window (fixed at load; §5) --------------------------------
  const nowMs = useMemo(() => Date.now(), []);
  const windowStart = nowMs - WINDOW_HALF_MS;
  const windowEnd = nowMs + WINDOW_HALF_MS;
  const handoff = useMemo(() => parseHandoff(nowMs), [nowMs]);

  const dotsPolicy: FlowPatientDots | null = lens?.patient_dots ?? null;
  const patientDotsVisible = dotsPolicy !== 'none';

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
  const projectionsRef = useRef<ProjectionItem[]>([]);
  const barriersRef = useRef<NavigatorBarrier[]>([]);
  const currentTimeRef = useRef(handoff.t ?? nowMs);
  const speedRef = useRef(60);
  const playingRef = useRef(false);
  const liveRef = useRef(false);
  const eventSourceRef = useRef<EventSource | null>(null);
  const lastVisibleStatesRef = useRef<PatientVisibleState[]>([]);
  const lastBucketKeyRef = useRef('');
  const lastTimeEmitRef = useRef(0);
  const scopeAppliedRef = useRef(false);

  const [summary, setSummary] = useState<PatientFlowSummary | null>(null);
  const [ambient, setAmbient] = useState<PatientFlowAmbient | null>(null);
  const [locations, setLocations] = useState<PatientFlowLocations>({});
  const [events, setEvents] = useState<PatientFlowEvent[]>([]);
  const [projections, setProjections] = useState<ProjectionItem[]>([]);
  const [barriers, setBarriers] = useState<NavigatorBarrier[]>([]);
  const [filters, setFilters] = useState<PatientFlowFilters>(filtersRef.current);
  const [layers, setLayers] = useState<PatientLayerState>(layersRef.current);
  const [currentTime, setCurrentTime] = useState(currentTimeRef.current);
  const [speed, setSpeed] = useState(60);
  const [playing, setPlaying] = useState(false);
  const [live, setLive] = useState(false);
  const [status, setStatus] = useState('Loading');
  const [cameraText, setCameraText] = useState('');
  const [metrics, setMetrics] = useState<NavigatorMetrics>({ active: 0, events: 0, occupiedLocations: 0 });
  const [forecast, setForecast] = useState<ForecastAggregates | null>(null);
  const [inspectorTitle, setInspectorTitle] = useState('Select a patient or location');
  const [inspectorRows, setInspectorRows] = useState<Array<[string, string]>>([]);
  const [feed, setFeed] = useState<PatientFlowEvent[]>([]);
  const [error, setError] = useState<string | null>(null);

  const tracks = useMemo(() => rebuildTracks(events), [events]);

  const dataStart = useMemo(() => (events.length ? parseTime(events[0].occurred_at) : null), [events]);
  const dataEnd = useMemo(
    () => (events.length ? parseTime(events[events.length - 1].occurred_at) : null),
    [events],
  );

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
    controls.push({ key: 'barriers', label: 'Barriers', id: 'flow-layer-barriers' });
    if (!lens || (lens.layers.includes('projections') && lens.projection_kinds.length > 0)) {
      controls.push({ key: 'ghosts', label: 'Forecast', id: 'flow-layer-forecast' });
    }
    return controls;
  }, [lens, patientDotsVisible]);

  // ---- scene refresh: cheap per-frame tokens, bucketed heavy layers -------
  const refreshScene = useCallback(() => {
    const scene = sceneRef.current;
    if (!scene) return;

    const timeMs = currentTimeRef.current;
    const states = patientStatesAt(tracksRef.current, locationsRef.current, timeMs, filtersRef.current);
    lastVisibleStatesRef.current = states;
    scene.updateTokens(
      states,
      layersRef.current.tokens && dotsPolicy !== 'none',
      dotsPolicy !== null && dotsPolicy !== 'full',
    );

    // Heavy layers rebuild only when the sim-time minute bucket, filters,
    // layers, or datasets change — not every animation frame.
    const bucketKey = [
      Math.floor(timeMs / 60_000),
      JSON.stringify(filtersRef.current),
      JSON.stringify(layersRef.current),
      eventsRef.current.length,
      projectionsRef.current.length,
      barriersRef.current.length,
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
    const occupied = scene.rebuildHeat(states, locationsRef.current, layersRef.current.heat);

    // Projection ghosts + forecast heat (the future half; §5 ghost grammar).
    const index = placementIndexRef.current;
    const floorFilter = filtersRef.current.floor;
    const ghostItems = layersRef.current.ghosts
      ? ghostsAt(projectionsRef.current, nowMs, timeMs).filter((item) => {
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
      ? aggregatesAt(projectionsRef.current, nowMs, timeMs)
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
    scene.rebuildForecastHeat(heatCells, layersRef.current.ghosts && timeMs > nowMs);
    setForecast(aggregates && timeMs > nowMs ? aggregates : null);

    // Open-barrier markers — present-state, so shown at every scrub position
    // (not gated on past/future), just placed on their unit + floor-filtered.
    const barrierCells = layersRef.current.barriers
      ? buildBarrierCells(barriersRef.current, index, floorFilter, nowMs)
      : [];
    scene.rebuildBarriers(barrierCells, layersRef.current.barriers);

    setMetrics({
      active: states.length,
      events: eventsRef.current.filter((event) => parseTime(event.occurred_at) <= timeMs).length,
      occupiedLocations: occupied || new Set(states.map((state) => state.event.to_location).filter(Boolean)).size,
    });
  }, [dotsPolicy, nowMs, patientDotsVisible]);

  // Keep refs in sync with state, then repaint.
  useEffect(() => {
    eventsRef.current = events;
    locationsRef.current = locations;
    filtersRef.current = filters;
    layersRef.current = layers;
    projectionsRef.current = projections;
    barriersRef.current = barriers;
    speedRef.current = speed;
    playingRef.current = playing;
    liveRef.current = live;
    tracksRef.current = tracks;
    placementIndexRef.current = placementIndex;
    refreshScene();
  }, [events, locations, filters, layers, projections, barriers, speed, playing, live, tracks, placementIndex, refreshScene]);

  // Repaint when the displayed time changes. The ref is the source of truth
  // (playback advances it per frame); scrub/live paths write it via applyTime.
  useEffect(() => {
    refreshScene();
  }, [currentTime, refreshScene]);

  const applyTime = useCallback((timeMs: number): void => {
    currentTimeRef.current = timeMs;
    setCurrentTime(timeMs);
  }, []);

  // ---- data bootstrap ------------------------------------------------------
  useEffect(() => {
    let cancelled = false;

    async function bootstrap(): Promise<void> {
      try {
        setStatus('Loading data');
        const [summaryData, locationData, eventData, ambientData] = await Promise.all([
          fetchPatientFlowSummary(),
          fetchPatientFlowLocations(),
          fetchPatientFlowEvents({ limit: 20000 }),
          fetchPatientFlowAmbient(),
        ]);
        if (cancelled) return;

        const sortedEvents = [...eventData].sort((a, b) => parseTime(a.occurred_at) - parseTime(b.occurred_at));
        setSummary(summaryData);
        setAmbient(ambientData);
        setLocations(locationData);
        setEvents(sortedEvents);
        if (!sortedEvents.length) {
          setStatus('No flow events loaded');
        } else if (parseTime(sortedEvents[sortedEvents.length - 1].occurred_at) < windowStart) {
          setStatus('Events precede the 48h window — rebase the synthetic fixture');
        } else {
          setStatus('Data loaded');
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
  }, [windowStart]);

  // Projection stream (future half) — lens-clamped server-side; a failure
  // only disables ghosts, never the navigator.
  useEffect(() => {
    let cancelled = false;
    fetchPatientFlowProjections(lens ? { persona: lens.role_id } : {})
      .then((payload) => {
        if (cancelled) return;
        const allowed = lens ? new Set(lens.projection_kinds) : null;
        setProjections(payload.projections.filter((item) => !allowed || allowed.has(item.kind)));
      })
      .catch(() => {
        if (!cancelled) setProjections([]);
      });
    return () => {
      cancelled = true;
    };
  }, [lens]);

  // Open barriers overlay — aggregate + patient-free (no lens needed); a failure
  // only hides the overlay, never the navigator.
  useEffect(() => {
    let cancelled = false;
    fetchPatientFlowBarriers()
      .then((payload) => {
        if (!cancelled) setBarriers(payload.open_barriers);
      })
      .catch(() => {
        if (!cancelled) setBarriers([]);
      });
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
        onCameraMove: setCameraText,
        onFrame: (delta) => {
          if (!playingRef.current || liveRef.current) return;
          const next = currentTimeRef.current + delta * speedRef.current * 60 * 1000;
          // Replay loops within the past half only; the future is for scrubbing.
          const bounded = next > nowMs ? windowStart : next;
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
          setStatus('Model loaded');
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
  }, [summary?.model_url, dotsPolicy, nowMs, windowStart, refreshScene]);

  // ---- playback / live -----------------------------------------------------
  const disconnectLive = useCallback((): void => {
    eventSourceRef.current?.close();
    eventSourceRef.current = null;
    setLive(false);
    setStatus('Historical replay');
  }, []);

  const connectLive = useCallback((): void => {
    eventSourceRef.current?.close();
    const source = createPatientFlowEventSource({ replay: 180, interval: 0.65 });
    eventSourceRef.current = source;
    source.addEventListener('patient-flow', (message) => {
      const event = JSON.parse((message as MessageEvent<string>).data) as PatientFlowEvent;
      setEvents((prev) => {
        if (prev.some((item) => item.event_id === event.event_id)) return prev;
        return [...prev, event].sort((a, b) => parseTime(a.occurred_at) - parseTime(b.occurred_at));
      });
      // Follow the stream inside the fixed window — the frame never resets.
      const eventTime = parseTime(event.occurred_at);
      applyTime(Math.min(nowMs, Math.max(windowStart, eventTime)));
      setFeed((prev) => [event, ...prev.filter((item) => item.event_id !== event.event_id)].slice(0, 8));
    });
    source.onerror = () => setStatus('Live stream reconnecting');
    setLive(true);
    setStatus('Live stream active');
  }, [applyTime, nowMs, windowStart]);

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
            forecast={forecast}
            barrierTicks={barrierTicks}
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
        metrics={metrics}
        onTogglePlay={() => {
          if (!playing) disconnectLive();
          setPlaying((value) => !value);
        }}
        onToggleLive={() => (live ? disconnectLive() : connectLive())}
        onResetCamera={resetCamera}
        onFocusPatients={focusActivePatients}
        onSpeedChange={setSpeed}
        onFiltersChange={(patch) => setFilters((prev) => ({ ...prev, ...patch }))}
        onLayerChange={(key, value) => setLayers((prev) => ({ ...prev, [key]: value }))}
      />

      <NavigatorFeed feed={feed} redactIdentity={dotsPolicy !== null && dotsPolicy !== 'full'} />

      <NavigatorInspector title={inspectorTitle} rows={inspectorRows} />

      <div className="patient-flow-statusbar">
        <span>{status}</span>
        <span>{ambient ? `Ambient ${Math.round(ambient.summary.averageConfidence * 100)}% ${ambient.summary.confidenceLevel}` : 'Ambient pending'}</span>
        <span className="patient-flow-camera">{cameraText}</span>
      </div>
    </section>
  );
}
