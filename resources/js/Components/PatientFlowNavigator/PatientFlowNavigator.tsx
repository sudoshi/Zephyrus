import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { Home, Pause, Play, Radio, ScanSearch } from 'lucide-react';
import {
  createPatientFlowEventSource,
  fetchPatientFlowAmbient,
  fetchPatientFlowEvents,
  fetchPatientFlowLocations,
  fetchPatientFlowSummary,
} from '@/features/patientFlowNavigator/api';
import {
  parseTime,
  patientStatesAt,
  positionFor,
  rebuildTracks,
} from '@/features/patientFlowNavigator/stateProjection';
import type {
  PatientFlowEvent,
  PatientFlowAmbient,
  PatientFlowFilters,
  PatientFlowLocations,
  PatientFlowSummary,
  PatientLayerState,
  PatientVisibleState,
} from '@/features/patientFlowNavigator/types';
import './PatientFlowNavigator.css';

interface PatientFlowNavigatorProps {
  initialFloor?: string;
  initialCategory?: string;
  initialServiceLine?: string;
}

interface SceneHandles {
  renderer: THREE.WebGLRenderer;
  scene: THREE.Scene;
  camera: THREE.PerspectiveCamera;
  orbit: OrbitControls;
  patientLayer: THREE.Group;
  trailLayer: THREE.Group;
  heatLayer: THREE.Group;
  baseObjects: THREE.Object3D[];
  tokenByPatient: Map<string, THREE.Mesh>;
  patientMaterials: Map<string, THREE.MeshStandardMaterial>;
  animationId: number;
  clock: THREE.Clock;
  tokenGeometry: THREE.SphereGeometry;
  heatGeometry: THREE.CylinderGeometry;
  raycaster: THREE.Raycaster;
}

interface Metrics {
  active: number;
  events: number;
  occupiedLocations: number;
}

const defaultLayers: PatientLayerState = {
  base: true,
  tokens: true,
  trails: true,
  heat: true,
};

const layerControls: Array<{ key: keyof PatientLayerState; label: string; id: string }> = [
  { key: 'base', label: 'Model', id: 'flow-layer-model' },
  { key: 'tokens', label: 'Patients', id: 'flow-layer-patients' },
  { key: 'trails', label: 'Trails', id: 'flow-layer-trails' },
  { key: 'heat', label: 'Census', id: 'flow-layer-census' },
];

function fmtTime(ms: number): string {
  if (!Number.isFinite(ms) || ms <= 0) return '--';
  return new Date(ms).toLocaleString([], {
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function hashColor(value: string): THREE.Color {
  let hash = 0;
  for (let index = 0; index < value.length; index += 1) {
    hash = ((hash << 5) - hash) + value.charCodeAt(index);
  }
  return new THREE.Color(`hsl(${Math.abs(hash) % 360}, 70%, 58%)`);
}

function disposeObject(object: THREE.Object3D): void {
  const mesh = object as THREE.Mesh;
  mesh.geometry?.dispose?.();
  const material = mesh.material;
  if (Array.isArray(material)) {
    material.forEach((item) => item.dispose());
  } else {
    material?.dispose?.();
  }
}

function clearGroup(group: THREE.Group): void {
  while (group.children.length) {
    const child = group.children.pop();
    if (child) disposeObject(child);
  }
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
}: PatientFlowNavigatorProps) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const containerRef = useRef<HTMLDivElement | null>(null);
  const sceneRef = useRef<SceneHandles | null>(null);
  const tracksRef = useRef<Map<string, PatientFlowEvent[]>>(new Map());
  const eventsRef = useRef<PatientFlowEvent[]>([]);
  const locationsRef = useRef<PatientFlowLocations>({});
  const filtersRef = useRef<PatientFlowFilters>({ floor: initialFloor, serviceLine: initialServiceLine, category: initialCategory, search: '' });
  const layersRef = useRef<PatientLayerState>(defaultLayers);
  const currentTimeRef = useRef(0);
  const minTimeRef = useRef(0);
  const maxTimeRef = useRef(0);
  const speedRef = useRef(60);
  const playingRef = useRef(false);
  const liveRef = useRef(false);
  const eventSourceRef = useRef<EventSource | null>(null);
  const lastVisibleStatesRef = useRef<PatientVisibleState[]>([]);

  const [summary, setSummary] = useState<PatientFlowSummary | null>(null);
  const [ambient, setAmbient] = useState<PatientFlowAmbient | null>(null);
  const [locations, setLocations] = useState<PatientFlowLocations>({});
  const [events, setEvents] = useState<PatientFlowEvent[]>([]);
  const [filters, setFilters] = useState<PatientFlowFilters>(filtersRef.current);
  const [layers, setLayers] = useState<PatientLayerState>(defaultLayers);
  const [currentTime, setCurrentTime] = useState(0);
  const [minTime, setMinTime] = useState(0);
  const [maxTime, setMaxTime] = useState(0);
  const [speed, setSpeed] = useState(60);
  const [playing, setPlaying] = useState(false);
  const [live, setLive] = useState(false);
  const [status, setStatus] = useState('Loading');
  const [cameraText, setCameraText] = useState('');
  const [metrics, setMetrics] = useState<Metrics>({ active: 0, events: 0, occupiedLocations: 0 });
  const [inspectorTitle, setInspectorTitle] = useState('Select a patient or location');
  const [inspectorRows, setInspectorRows] = useState<Array<[string, string]>>([]);
  const [feed, setFeed] = useState<PatientFlowEvent[]>([]);
  const [error, setError] = useState<string | null>(null);

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

  const syncRefs = useCallback(() => {
    eventsRef.current = events;
    locationsRef.current = locations;
    filtersRef.current = filters;
    layersRef.current = layers;
    currentTimeRef.current = currentTime;
    minTimeRef.current = minTime;
    maxTimeRef.current = maxTime;
    speedRef.current = speed;
    playingRef.current = playing;
    liveRef.current = live;
    tracksRef.current = rebuildTracks(events);
  }, [currentTime, events, filters, layers, live, locations, maxTime, minTime, playing, speed]);

  useEffect(() => {
    syncRefs();
  }, [syncRefs]);

  const materialForPatient = useCallback((handles: SceneHandles, patientId: string): THREE.MeshStandardMaterial => {
    let material = handles.patientMaterials.get(patientId);
    if (!material) {
      const color = hashColor(patientId);
      material = new THREE.MeshStandardMaterial({
        color,
        emissive: color.clone().multiplyScalar(0.22),
        roughness: 0.42,
        metalness: 0,
      });
      handles.patientMaterials.set(patientId, material);
    }

    return material;
  }, []);

  const updateBaseVisibility = useCallback((handles: SceneHandles) => {
    const floor = filtersRef.current.floor;
    for (const object of handles.baseObjects) {
      const data = object.userData ?? {};
      const floorOk = floor === 'all' || String(data.floor) === floor || data.category === 'elevator';
      object.visible = layersRef.current.base && floorOk;
    }
  }, []);

  const updateTokens = useCallback((handles: SceneHandles, states: PatientVisibleState[]) => {
    const visible = new Set<string>();
    for (const state of states) {
      let token = handles.tokenByPatient.get(state.patientId);
      if (!token) {
        token = new THREE.Mesh(handles.tokenGeometry, materialForPatient(handles, state.patientId));
        handles.tokenByPatient.set(state.patientId, token);
        handles.patientLayer.add(token);
      }
      token.position.set(state.position.x, state.position.y, state.position.z);
      token.scale.setScalar(state.event.event_category === 'movement' ? 1 : 0.82);
      token.visible = layersRef.current.tokens;
      token.userData = {
        kind: 'patient-token',
        patient_id: state.patientId,
        patient_display_id: state.event.patient_display_id,
        current_location: state.event.to_location,
        service_line: state.event.service_line,
        event_type: state.event.event_type,
        event_category: state.event.event_category,
        last_event_at: state.event.occurred_at,
        recent_event_count: state.recent.length,
        encounter_id: state.event.encounter_id,
      };
      visible.add(state.patientId);
    }

    for (const [patientId, token] of handles.tokenByPatient.entries()) {
      if (!visible.has(patientId)) token.visible = false;
    }
  }, [materialForPatient]);

  const updateTrails = useCallback((handles: SceneHandles, states: PatientVisibleState[], timeMs: number) => {
    clearGroup(handles.trailLayer);
    if (!layersRef.current.trails) return;

    const activePatients = new Set(states.map((state) => state.patientId));
    for (const [patientId, track] of tracksRef.current.entries()) {
      if (!activePatients.has(patientId)) continue;
      const points: THREE.Vector3[] = [];
      for (const event of track) {
        if (parseTime(event.occurred_at) > timeMs) break;
        const position = positionFor(locationsRef.current, event.to_location);
        if (!position) continue;
        const vector = new THREE.Vector3(position.x, position.y, position.z);
        if (!points.length || !points[points.length - 1].equals(vector)) points.push(vector);
      }
      if (points.length < 2) continue;
      const geometry = new THREE.BufferGeometry().setFromPoints(points);
      const material = new THREE.LineBasicMaterial({ color: hashColor(patientId), transparent: true, opacity: 0.55 });
      const line = new THREE.Line(geometry, material);
      line.userData = { kind: 'patient-trail', patient_id: patientId };
      handles.trailLayer.add(line);
    }
  }, []);

  const updateHeat = useCallback((handles: SceneHandles, states: PatientVisibleState[]) => {
    clearGroup(handles.heatLayer);
    if (!layersRef.current.heat) {
      setMetrics((prev) => ({ ...prev, occupiedLocations: 0 }));
      return;
    }

    const occupancy = new Map<string, number>();
    for (const state of states) {
      const loc = state.event.to_location;
      if (loc) occupancy.set(loc, (occupancy.get(loc) ?? 0) + 1);
    }

    for (const [loc, count] of occupancy.entries()) {
      const position = positionFor(locationsRef.current, loc);
      if (!position) continue;
      const material = new THREE.MeshStandardMaterial({
        color: count > 1 ? 0xf06755 : 0x77c06f,
        emissive: count > 1 ? 0x5a140d : 0x143d17,
        transparent: true,
        opacity: 0.62,
      });
      const marker = new THREE.Mesh(handles.heatGeometry, material);
      marker.position.set(position.x, position.y + 2 + count * 0.42, position.z);
      marker.scale.set(1 + count * 0.12, Math.max(1, count * 1.2), 1 + count * 0.12);
      marker.userData = {
        kind: 'occupancy-marker',
        location: loc,
        active_patient_count: count,
        location_name: locationsRef.current[loc]?.name,
      };
      handles.heatLayer.add(marker);
    }
  }, []);

  const updateScene = useCallback(() => {
    const handles = sceneRef.current;
    if (!handles || !eventsRef.current.length) return;
    const states = patientStatesAt(tracksRef.current, locationsRef.current, currentTimeRef.current, filtersRef.current);
    lastVisibleStatesRef.current = states;
    updateBaseVisibility(handles);
    updateTokens(handles, states);
    updateTrails(handles, states, currentTimeRef.current);
    updateHeat(handles, states);
    setMetrics({
      active: states.length,
      events: eventsRef.current.filter((event) => parseTime(event.occurred_at) <= currentTimeRef.current).length,
      occupiedLocations: new Set(states.map((state) => state.event.to_location).filter(Boolean)).size,
    });
  }, [updateBaseVisibility, updateHeat, updateTokens, updateTrails]);

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
        const nextMin = sortedEvents.length ? parseTime(sortedEvents[0].occurred_at) : 0;
        const nextMax = sortedEvents.length ? parseTime(sortedEvents[sortedEvents.length - 1].occurred_at) : 0;
        setSummary(summaryData);
        setAmbient(ambientData);
        setLocations(locationData);
        setEvents(sortedEvents);
        setMinTime(nextMin);
        setMaxTime(nextMax);
        setCurrentTime(nextMax);
        setStatus(sortedEvents.length ? 'Data loaded' : 'No flow events loaded');
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
  }, []);

  useEffect(() => {
    syncRefs();
    updateScene();
  }, [syncRefs, updateScene]);

  useEffect(() => {
    const canvas = canvasRef.current;
    const container = containerRef.current;
    if (!canvas || !container || !summary?.model_url) return;

    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, preserveDrawingBuffer: true });
    const width = container.clientWidth;
    const height = container.clientHeight;
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(width, height);
    renderer.outputColorSpace = THREE.SRGBColorSpace;

    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x121514);
    scene.fog = new THREE.Fog(0x121514, 150, 470);

    const camera = new THREE.PerspectiveCamera(60, width / height, 0.1, 1400);
    camera.position.set(88, 104, 162);

    const orbit = new OrbitControls(camera, renderer.domElement);
    orbit.target.set(0, 48, 0);
    orbit.enableDamping = true;
    orbit.maxPolarAngle = Math.PI * 0.49;
    orbit.minDistance = 18;
    orbit.maxDistance = 380;

    scene.add(new THREE.HemisphereLight(0xf6f0e4, 0x343a36, 2.2));
    const sun = new THREE.DirectionalLight(0xfff5df, 2);
    sun.position.set(-85, 170, 80);
    scene.add(sun);
    const grid = new THREE.GridHelper(190, 19, 0x796e59, 0x333834);
    grid.position.y = -0.12;
    scene.add(grid);

    const patientLayer = new THREE.Group();
    const trailLayer = new THREE.Group();
    const heatLayer = new THREE.Group();
    scene.add(heatLayer, trailLayer, patientLayer);

    const handles: SceneHandles = {
      renderer,
      scene,
      camera,
      orbit,
      patientLayer,
      trailLayer,
      heatLayer,
      baseObjects: [],
      tokenByPatient: new Map(),
      patientMaterials: new Map(),
      animationId: 0,
      clock: new THREE.Clock(),
      tokenGeometry: new THREE.SphereGeometry(1.65, 18, 12),
      heatGeometry: new THREE.CylinderGeometry(1.9, 1.9, 1, 18, 1),
      raycaster: new THREE.Raycaster(),
    };
    sceneRef.current = handles;

    new GLTFLoader().load(summary.model_url, (gltf) => {
      scene.add(gltf.scene);
      gltf.scene.traverse((object) => {
        const mesh = object as THREE.Mesh;
        if (!mesh.isMesh) return;
        handles.baseObjects.push(mesh);
        const material = mesh.material;
        if (Array.isArray(material)) {
          mesh.material = material.map((item) => item.clone());
          mesh.material.forEach((item) => {
            item.transparent = true;
            item.opacity = mesh.userData?.category === 'floor' ? 0.56 : 0.72;
          });
        } else if (material) {
          mesh.material = material.clone();
          mesh.material.transparent = true;
          mesh.material.opacity = mesh.userData?.category === 'floor' ? 0.56 : 0.72;
        }
      });
      setStatus('Model loaded');
      updateScene();
    }, undefined, (loadError) => {
      console.error(loadError);
      setError('Model failed to load');
      setStatus('Model failed to load');
    });

    const onResize = (): void => {
      const nextWidth = container.clientWidth;
      const nextHeight = container.clientHeight;
      camera.aspect = nextWidth / nextHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(nextWidth, nextHeight);
    };

    const onPointerDown = (event: PointerEvent): void => {
      if (event.target !== renderer.domElement) return;
      const rect = renderer.domElement.getBoundingClientRect();
      const pointer = new THREE.Vector2(
        ((event.clientX - rect.left) / rect.width) * 2 - 1,
        -((event.clientY - rect.top) / rect.height) * 2 + 1,
      );
      handles.raycaster.setFromCamera(pointer, camera);
      const hits = handles.raycaster.intersectObjects([
        ...patientLayer.children,
        ...heatLayer.children,
        ...handles.baseObjects,
      ].filter((object) => object.visible), false);
      if (!hits.length) return;
      const data = hits[0].object.userData ?? {};
      setInspectorTitle(String(data.patient_display_id ?? data.name ?? data.code ?? data.kind ?? 'Selected'));
      setInspectorRows(flattenInspector(data));
    };

    const animate = (): void => {
      const delta = Math.min(handles.clock.getDelta(), 0.05);
      if (playingRef.current && !liveRef.current && maxTimeRef.current > minTimeRef.current) {
        const next = currentTimeRef.current + delta * speedRef.current * 60 * 1000;
        const bounded = next > maxTimeRef.current ? minTimeRef.current : next;
        currentTimeRef.current = bounded;
        setCurrentTime(bounded);
        updateScene();
      }
      orbit.update();
      setCameraText(`x ${camera.position.x.toFixed(0)} y ${camera.position.y.toFixed(0)} z ${camera.position.z.toFixed(0)}`);
      renderer.render(scene, camera);
      handles.animationId = requestAnimationFrame(animate);
    };

    window.addEventListener('resize', onResize);
    renderer.domElement.addEventListener('pointerdown', onPointerDown);
    handles.animationId = requestAnimationFrame(animate);

    return () => {
      window.removeEventListener('resize', onResize);
      renderer.domElement.removeEventListener('pointerdown', onPointerDown);
      cancelAnimationFrame(handles.animationId);
      eventSourceRef.current?.close();
      handles.baseObjects.forEach(disposeObject);
      clearGroup(patientLayer);
      clearGroup(trailLayer);
      clearGroup(heatLayer);
      handles.patientMaterials.forEach((material) => material.dispose());
      handles.tokenGeometry.dispose();
      handles.heatGeometry.dispose();
      orbit.dispose();
      renderer.dispose();
      sceneRef.current = null;
    };
  }, [summary?.model_url]);

  const setTimelineFromSlider = (value: string): void => {
    const pct = Number(value) / 10000;
    const next = minTime + pct * (maxTime - minTime);
    disconnectLive();
    setCurrentTime(next);
  };

  const connectLive = (): void => {
    eventSourceRef.current?.close();
    const source = createPatientFlowEventSource({ replay: 180, interval: 0.65 });
    eventSourceRef.current = source;
    source.addEventListener('patient-flow', (message) => {
      const event = JSON.parse((message as MessageEvent<string>).data) as PatientFlowEvent;
      setEvents((prev) => {
        if (prev.some((item) => item.event_id === event.event_id)) return prev;
        const next = [...prev, event].sort((a, b) => parseTime(a.occurred_at) - parseTime(b.occurred_at));
        const nextMax = Math.max(...next.map((item) => parseTime(item.occurred_at)));
        setMaxTime(nextMax);
        setCurrentTime(nextMax);
        return next;
      });
      setFeed((prev) => [event, ...prev.filter((item) => item.event_id !== event.event_id)].slice(0, 8));
    });
    source.onerror = () => setStatus('Live stream reconnecting');
    setLive(true);
    setStatus('Live stream active');
  };

  const disconnectLive = (): void => {
    eventSourceRef.current?.close();
    eventSourceRef.current = null;
    setLive(false);
    setStatus('Historical replay');
  };

  const resetCamera = (): void => {
    const handles = sceneRef.current;
    if (!handles) return;
    handles.camera.position.set(88, 104, 162);
    handles.orbit.target.set(0, 48, 0);
    handles.orbit.update();
  };

  const focusActivePatients = (): void => {
    const handles = sceneRef.current;
    const states = lastVisibleStatesRef.current;
    if (!handles || !states.length) return;
    const box = new THREE.Box3();
    states.forEach((state) => box.expandByPoint(new THREE.Vector3(state.position.x, state.position.y, state.position.z)));
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const radius = Math.max(size.x, size.y, size.z, 24);
    handles.orbit.target.copy(center);
    handles.camera.position.set(center.x + radius * 1.35, center.y + radius * 1.05, center.z + radius * 1.35);
    handles.orbit.update();
  };

  const sliderValue = maxTime === minTime ? 10000 : Math.round(((currentTime - minTime) / (maxTime - minTime)) * 10000);

  return (
    <section ref={containerRef} className="patient-flow-shell" aria-label="Patient Flow 4D Navigator">
      <canvas ref={canvasRef} className="patient-flow-canvas" aria-label="Patient flow 3D navigator" />

      {error && <div className="patient-flow-error">{error}</div>}

      <aside className="patient-flow-toolbar" aria-label="Navigator controls">
        <div className="patient-flow-brand">
          <strong>Patient Flow 4D</strong>
          <span>{summary ? `${summary.patients} pts / ${summary.normalized_events} events` : 'Loading'}</span>
        </div>

        <div className="patient-flow-time">
          <output>{fmtTime(currentTime)}</output>
          <input
            type="range"
            min="0"
            max="10000"
            value={Number.isFinite(sliderValue) ? sliderValue : 0}
            onChange={(event) => setTimelineFromSlider(event.target.value)}
          />
        </div>

        <div className="patient-flow-buttons">
          <button
            className={`patient-flow-icon-button ${playing ? 'active' : ''}`}
            type="button"
            title={playing ? 'Pause replay' : 'Play replay'}
            onClick={() => {
              if (!playing) disconnectLive();
              setPlaying((value) => !value);
            }}
          >
            {playing ? <Pause /> : <Play />}
          </button>
          <button
            className={`patient-flow-icon-button ${live ? 'active' : ''}`}
            type="button"
            title="Live stream"
            onClick={() => (live ? disconnectLive() : connectLive())}
          >
            <Radio />
          </button>
          <button className="patient-flow-icon-button" type="button" title="Reset view" onClick={resetCamera}>
            <Home />
          </button>
          <button className="patient-flow-icon-button" type="button" title="Focus active patients" onClick={focusActivePatients}>
            <ScanSearch />
          </button>
        </div>

        <div className="patient-flow-control-grid">
          <label htmlFor="flow-floor">Floor</label>
          <select id="flow-floor" value={filters.floor} onChange={(event) => setFilters((prev) => ({ ...prev, floor: event.target.value }))}>
            <option value="all">All</option>
            {floors.map((floor) => <option key={floor} value={floor}>Floor {floor}</option>)}
          </select>

          <label htmlFor="flow-service">Service</label>
          <select id="flow-service" value={filters.serviceLine} onChange={(event) => setFilters((prev) => ({ ...prev, serviceLine: event.target.value }))}>
            <option value="all">All</option>
            {services.map((service) => <option key={service} value={service}>{service.replaceAll('_', ' ')}</option>)}
          </select>

          <label htmlFor="flow-category">Event</label>
          <select id="flow-category" value={filters.category} onChange={(event) => setFilters((prev) => ({ ...prev, category: event.target.value }))}>
            <option value="all">All</option>
            {categories.map((category) => <option key={category} value={category}>{category.replaceAll('_', ' ')}</option>)}
          </select>

          <label htmlFor="flow-speed">Speed</label>
          <select id="flow-speed" value={speed} onChange={(event) => setSpeed(Number(event.target.value))}>
            <option value={15}>15m/s</option>
            <option value={60}>1h/s</option>
            <option value={240}>4h/s</option>
            <option value={720}>12h/s</option>
          </select>

          <label htmlFor="flow-search">Find</label>
          <input
            id="flow-search"
            type="search"
            placeholder="PT, bed, service"
            value={filters.search}
            onChange={(event) => setFilters((prev) => ({ ...prev, search: event.target.value }))}
          />
        </div>

        <fieldset className="patient-flow-layer-grid">
          <legend>Layers</legend>
          {layerControls.map(({ key, label, id }) => (
            <div className="patient-flow-checkbox-row" key={key}>
              <input
                id={id}
                type="checkbox"
                checked={layers[key]}
                onChange={(event) => setLayers((prev) => ({ ...prev, [key]: event.target.checked }))}
              />
              <label htmlFor={id}>{label}</label>
            </div>
          ))}
        </fieldset>

        <div className="patient-flow-metrics">
          <div><span>{metrics.active}</span><small>Active</small></div>
          <div><span>{metrics.events}</span><small>Events</small></div>
          <div><span>{metrics.occupiedLocations}</span><small>Locations</small></div>
          <div><span>{ambient?.summary.eventCount ?? summary?.ambient_signals ?? 0}</span><small>Ambient</small></div>
        </div>
      </aside>

      <aside className="patient-flow-feed" aria-label="Live event feed">
        <strong>Stream</strong>
        <ol>
          {feed.map((event) => (
            <li key={event.event_id}>
              <span>{new Date(event.occurred_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
              <span>{event.patient_display_id} {event.event_type} {event.to_location}</span>
            </li>
          ))}
        </ol>
      </aside>

      <aside className="patient-flow-inspector" aria-live="polite">
        <strong>{inspectorTitle}</strong>
        <dl>
          {inspectorRows.map(([key, value]) => (
            <React.Fragment key={key}>
              <dt>{key}</dt>
              <dd>{value}</dd>
            </React.Fragment>
          ))}
        </dl>
      </aside>

      <div className="patient-flow-statusbar">
        <span>{status}</span>
        <span>{ambient ? `Ambient ${Math.round(ambient.summary.averageConfidence * 100)}% ${ambient.summary.confidenceLevel}` : 'Ambient pending'}</span>
        <span className="patient-flow-camera">{cameraText}</span>
      </div>
    </section>
  );
}
