import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { parseTime, positionFor } from '@/features/patientFlowNavigator/stateProjection';
import { confidenceOpacity } from '@/features/patientFlowNavigator/projections';
import type { BarrierCell, BarrierSeverity, ProjectionAnchor } from '@/features/patientFlowNavigator/projections';
import {
  BARRIER_COLORS,
  BASE_CATEGORY_STYLES,
  FORECAST_COLOR,
  GHOST_COLORS,
  OCCUPANCY_STATUS_COLORS,
  ROUND_PINNED_COLOR,
  ROUND_STOP_COLORS,
  TIMER_PIP_COLORS,
  patientHue,
} from '@/features/patientFlowNavigator/sceneVocabulary';
import type { RoundRouteSegment, RoundStopCell } from '@/features/virtualRounds/roundsScene';
import type {
  OccupancyInsight,
  OccupancyTimerStatus,
  PatientFlowEvent,
  PatientFlowLocations,
  PatientVisibleState,
  ProjectionItem,
} from '@/features/patientFlowNavigator/types';
import { formatDurationMinutes, formatRelativeDurationMinutes } from '@/lib/duration';

/**
 * Three.js scene lifecycle for the 4D Navigator — no React in here.
 *
 * This module is the ONLY importer of `three`, and the orchestrator loads it
 * via dynamic import so the whole 3D stack lands in its own lazy chunk.
 *
 * Perf contract (FLOW-WINDOW-PLAN §7.3): token positions may be updated every
 * frame; trails / census heat / ghosts are REBUILT only when the orchestrator
 * says the time bucket, filters, or layers changed. Geometries and materials
 * are cached and reused across rebuilds.
 */

export interface CameraView {
  position: { x: number; y: number; z: number };
  target: { x: number; y: number; z: number };
}

export interface NavigatorSceneCallbacks {
  onSelect: (data: Record<string, unknown>) => void;
  onCameraMove: (view: CameraView) => void;
  onFrame: (deltaSeconds: number) => void;
  /**
   * Operator-readable hover chip text for a hit's userData, or null to hide
   * the chip. The orchestrator owns this so lens redaction happens outside
   * the scene; the scene writes it via textContent (never HTML).
   */
  hoverLabel?: (data: Record<string, unknown>) => string | null;
  /**
   * Fired when the OPERATOR starts moving the camera (OrbitControls 'start' —
   * never programmatic flights). The tour's Auto mode pauses on this (R-6a:
   * respect the operator's hand).
   */
  onUserCameraStart?: () => void;
}

export interface GhostRenderItem {
  item: ProjectionItem;
  anchor: ProjectionAnchor;
}

export interface ForecastHeatCell {
  anchor: ProjectionAnchor;
  value: number;
  opacity: number;
}

// Shape/color constants live in features/patientFlowNavigator/sceneVocabulary
// (§5.1 SSOT) — the legend renders from the same module, so it can never lie.

const BARRIER_SCALE: Record<BarrierSeverity, number> = { critical: 1.3, warning: 1.1, watch: 1 };

const HOME_POSITION = new THREE.Vector3(88, 104, 162);
const HOME_TARGET = new THREE.Vector3(0, 48, 0);

export class NavigatorScene {
  private renderer: THREE.WebGLRenderer;

  private scene: THREE.Scene;

  private camera: THREE.PerspectiveCamera;

  private orbit: OrbitControls;

  private patientLayer = new THREE.Group();

  private trailLayer = new THREE.Group();

  private heatLayer = new THREE.Group();

  private ghostLayer = new THREE.Group();

  private forecastLayer = new THREE.Group();

  private barrierLayer = new THREE.Group();

  private roundsLayer = new THREE.Group();

  // Route + queue-number annotations live outside the raycast set — they are
  // wayfinding, not clickable objects.
  private roundsRouteLayer = new THREE.Group();

  private queueSpriteMaterials = new Map<number, THREE.SpriteMaterial>();

  private routeSolidMaterial: THREE.LineBasicMaterial | null = null;

  private routeDashedMaterial: THREE.LineDashedMaterial | null = null;

  private baseObjects: THREE.Object3D[] = [];

  private tokenByPatient = new Map<string, THREE.Mesh>();

  private patientMaterials = new Map<string, THREE.MeshStandardMaterial>();

  private trailMaterials = new Map<string, THREE.LineBasicMaterial>();

  private ghostMaterials = new Map<string, THREE.MeshStandardMaterial>();

  private forecastMaterials = new Map<string, THREE.MeshStandardMaterial>();

  private barrierMaterials = new Map<BarrierSeverity, THREE.MeshStandardMaterial>();

  private roundMaterials = new Map<string, THREE.MeshStandardMaterial>();

  private roundStopMeshByUuid = new Map<string, THREE.Mesh>();

  private baseCategoryMaterials = new Map<string, THREE.MeshStandardMaterial>();

  private focusedRoundStopUuid: string | null = null;

  private heatSingleMaterial: THREE.MeshStandardMaterial;

  private heatMultiMaterial: THREE.MeshStandardMaterial;

  private occupancyMaterials = new Map<OccupancyTimerStatus, THREE.MeshStandardMaterial>();

  private timerPipMaterials = new Map<OccupancyTimerStatus, THREE.MeshStandardMaterial>();

  private tokenGeometry = new THREE.SphereGeometry(1.65, 18, 12);

  private ghostGeometry = new THREE.SphereGeometry(1.45, 14, 10);

  private heatGeometry = new THREE.CylinderGeometry(2.7, 2.7, 0.18, 40, 1);

  private timerPipGeometry = new THREE.CylinderGeometry(0.28, 0.28, 0.42, 12, 1);

  private forecastGeometry = new THREE.CylinderGeometry(2.6, 2.6, 1, 18, 1);

  // A diamond, distinct from every other layer's shape — a barrier reads as a
  // marker, not census/forecast volume.
  private barrierGeometry = new THREE.OctahedronGeometry(2.1);

  // A flat ring, distinct from spheres/pillars/diamonds — a round stop reads
  // as "someone still has to visit here", not census or a barrier.
  private roundGeometry = new THREE.TorusGeometry(2.2, 0.42, 10, 26);

  private raycaster = new THREE.Raycaster();

  private clock = new THREE.Clock();

  private animationId = 0;

  private lastCameraText = '';

  private lastCameraEmit = 0;

  private lastHoverCast = 0;

  private hoverEnabled = true;

  private hoveredMesh: THREE.Mesh | null = null;

  private hoveredOriginalMaterial: THREE.Material | THREE.Material[] | null = null;

  private hoverMaterial: THREE.MeshStandardMaterial | null = null;

  private hoverChip: HTMLDivElement;

  private selectedMesh: THREE.Mesh | null = null;

  private selectedOriginalMaterial: THREE.Material | THREE.Material[] | null = null;

  private selectionMaterial: THREE.MeshStandardMaterial | null = null;

  private disposed = false;

  private readonly container: HTMLElement;

  private readonly callbacks: NavigatorSceneCallbacks;

  private readonly onResize = (): void => {
    const width = this.container.clientWidth;
    const height = this.container.clientHeight;
    this.camera.aspect = width / height;
    this.camera.updateProjectionMatrix();
    this.renderer.setSize(width, height);
  };

  /** The one interactive set — pointerdown select and pointermove hover agree. */
  private raycastHit(event: PointerEvent): THREE.Mesh | null {
    const rect = this.renderer.domElement.getBoundingClientRect();
    const pointer = new THREE.Vector2(
      ((event.clientX - rect.left) / rect.width) * 2 - 1,
      -((event.clientY - rect.top) / rect.height) * 2 + 1,
    );
    this.raycaster.setFromCamera(pointer, this.camera);
    const hits = this.raycaster.intersectObjects(
      [
        ...this.roundsLayer.children,
        ...this.barrierLayer.children,
        ...this.patientLayer.children,
        ...this.ghostLayer.children,
        ...this.heatLayer.children,
        ...this.baseObjects,
      ].filter((object) => object.visible),
      false,
    );
    return hits.length ? (hits[0].object as THREE.Mesh) : null;
  }

  private hitData(mesh: THREE.Mesh): Record<string, unknown> {
    return {
      ...(mesh.userData ?? {}),
      ...(mesh.name && !mesh.userData?.kind ? { name: mesh.name } : {}),
    };
  }

  private readonly onPointerDown = (event: PointerEvent): void => {
    if (event.target !== this.renderer.domElement) return;
    const mesh = this.raycastHit(event);
    if (!mesh) return;
    // E-5: hover clone must never be captured as a selection "original".
    this.clearHover();
    this.applySelection(mesh);
    this.callbacks.onSelect(this.hitData(mesh));
  };

  /**
   * E-4: throttled hover — cursor affordance, emissive lift via a non-shared
   * clone (the focused-round pattern), and a redacted HTML chip at the cursor.
   */
  private readonly onPointerMove = (event: PointerEvent): void => {
    if (!this.hoverEnabled) return;
    if (event.target !== this.renderer.domElement) {
      this.clearHover();
      return;
    }
    const now = performance.now();
    if (now - this.lastHoverCast < 50) return;
    this.lastHoverCast = now;

    const mesh = this.raycastHit(event);
    if (!mesh) {
      this.clearHover();
      return;
    }
    if (mesh !== this.hoveredMesh) {
      this.clearHover();
      this.applyHover(mesh);
    }

    const label = this.callbacks.hoverLabel?.(this.hitData(mesh)) ?? null;
    if (label) {
      const containerRect = this.container.getBoundingClientRect();
      this.hoverChip.textContent = label;
      this.hoverChip.style.left = `${event.clientX - containerRect.left + 14}px`;
      this.hoverChip.style.top = `${event.clientY - containerRect.top + 12}px`;
      this.hoverChip.hidden = false;
    } else {
      this.hoverChip.hidden = true;
    }
  };

  constructor(canvas: HTMLCanvasElement, container: HTMLElement, callbacks: NavigatorSceneCallbacks) {
    this.container = container;
    this.callbacks = callbacks;

    this.renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, preserveDrawingBuffer: true });
    const width = container.clientWidth;
    const height = container.clientHeight;
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.renderer.setSize(width, height);
    this.renderer.outputColorSpace = THREE.SRGBColorSpace;

    this.scene = new THREE.Scene();
    this.scene.background = new THREE.Color(0x121514);
    this.scene.fog = new THREE.Fog(0x121514, 150, 470);

    this.camera = new THREE.PerspectiveCamera(60, width / height, 0.1, 1400);
    this.camera.position.copy(HOME_POSITION);

    this.orbit = new OrbitControls(this.camera, this.renderer.domElement);
    this.orbit.target.copy(HOME_TARGET);
    this.orbit.enableDamping = true;
    this.orbit.maxPolarAngle = Math.PI * 0.49;
    this.orbit.minDistance = 18;
    this.orbit.maxDistance = 380;
    // N-6: arrow keys pan while the canvas has focus (click the scene first)
    // — scoped to the canvas so the floor rail's ↑/↓ stepping never collides.
    this.renderer.domElement.tabIndex = 0;
    this.orbit.listenToKeyEvents(this.renderer.domElement);

    this.scene.add(new THREE.HemisphereLight(0xf6f0e4, 0x343a36, 2.2));
    const sun = new THREE.DirectionalLight(0xfff5df, 2);
    sun.position.set(-85, 170, 80);
    this.scene.add(sun);
    const grid = new THREE.GridHelper(190, 19, 0x796e59, 0x333834);
    grid.position.y = -0.12;
    this.scene.add(grid);

    this.scene.add(this.forecastLayer, this.heatLayer, this.trailLayer, this.ghostLayer, this.patientLayer, this.barrierLayer, this.roundsLayer, this.roundsRouteLayer);

    // Tour Auto pauses when the OPERATOR grabs the camera; OrbitControls only
    // dispatches 'start' for real input, never for programmatic flights.
    this.orbit.addEventListener('start', () => this.callbacks.onUserCameraStart?.());

    this.heatSingleMaterial = new THREE.MeshStandardMaterial({
      color: 0x77c06f,
      emissive: 0x143d17,
      transparent: true,
      opacity: 0.62,
    });
    this.heatMultiMaterial = new THREE.MeshStandardMaterial({
      color: 0xf06755,
      emissive: 0x5a140d,
      transparent: true,
      opacity: 0.62,
    });

    // Hover chip: scene-owned so the 50 ms hover path never touches React.
    // Text is always set via textContent with orchestrator-redacted labels.
    this.hoverChip = document.createElement('div');
    this.hoverChip.className = 'patient-flow-hover-chip';
    this.hoverChip.hidden = true;
    container.appendChild(this.hoverChip);

    window.addEventListener('resize', this.onResize);
    this.renderer.domElement.addEventListener('pointerdown', this.onPointerDown);
    this.renderer.domElement.addEventListener('pointermove', this.onPointerMove);
    this.renderer.domElement.addEventListener('pointerleave', this.onPointerLeave);
    this.animationId = requestAnimationFrame(this.animate);
  }

  private readonly onPointerLeave = (): void => {
    this.clearHover();
  };

  /** Orchestrator gates hover off during fast playback (perf guard §5.5). */
  setHoverEnabled(enabled: boolean): void {
    this.hoverEnabled = enabled;
    if (!enabled) this.clearHover();
  }

  private applyHover(mesh: THREE.Mesh): void {
    this.hoveredMesh = mesh;
    this.renderer.domElement.style.cursor = 'pointer';
    // Selected/focused meshes already carry their own highlight clone —
    // hovering them must not capture that clone as an "original".
    if (mesh === this.selectedMesh || mesh === this.focusedRoundMesh) return;
    const material = mesh.material;
    if (Array.isArray(material) || !(material instanceof THREE.MeshStandardMaterial)) return;
    this.hoveredOriginalMaterial = material;
    const clone = material.clone();
    if (clone.emissive.getHex() === 0) {
      clone.emissive = clone.color.clone().multiplyScalar(0.35);
    }
    clone.emissiveIntensity = Math.max(clone.emissiveIntensity * 1.6, 1.1);
    mesh.material = clone;
    this.hoverMaterial = clone;
  }

  private clearHover(): void {
    if (this.hoveredMesh && this.hoveredOriginalMaterial) {
      this.hoveredMesh.material = this.hoveredOriginalMaterial;
    }
    this.hoverMaterial?.dispose();
    this.hoverMaterial = null;
    this.hoveredOriginalMaterial = null;
    this.hoveredMesh = null;
    this.renderer.domElement.style.cursor = '';
    this.hoverChip.hidden = true;
  }

  /**
   * E-5: persistent selection highlight — survives until the next selection,
   * Escape (orchestrator calls clearSelection), or a rebuild that removes the
   * mesh. Round stops keep their dedicated focus path.
   */
  private applySelection(mesh: THREE.Mesh): void {
    this.clearSelection();
    if (mesh === this.focusedRoundMesh) return;
    const material = mesh.material;
    if (Array.isArray(material) || !(material instanceof THREE.MeshStandardMaterial)) return;
    this.selectedMesh = mesh;
    this.selectedOriginalMaterial = material;
    const clone = material.clone();
    if (clone.emissive.getHex() === 0) {
      clone.emissive = clone.color.clone().multiplyScalar(0.4);
    }
    clone.emissiveIntensity = Math.max(clone.emissiveIntensity * 2, 1.8);
    mesh.material = clone;
    this.selectionMaterial = clone;
  }

  clearSelection(): void {
    if (this.selectedMesh && this.selectedOriginalMaterial) {
      this.selectedMesh.material = this.selectedOriginalMaterial;
    }
    this.selectionMaterial?.dispose();
    this.selectionMaterial = null;
    this.selectedOriginalMaterial = null;
    this.selectedMesh = null;
  }

  loadModel(url: string, onLoaded: () => void, onError: () => void): void {
    new GLTFLoader().load(
      url,
      (gltf) => {
        if (this.disposed) return;
        this.scene.add(gltf.scene);
        gltf.scene.traverse((object) => {
          const mesh = object as THREE.Mesh;
          if (!mesh.isMesh) return;
          this.baseObjects.push(mesh);

          // E-2: glTF extras carry category — beds/corridors/rooms/ED render
          // as distinct materials (sceneVocabulary §5.2). `floor` and unknown
          // categories keep the model's own material as the datum plane.
          const categoryMaterial = this.baseCategoryMaterialFor(String(mesh.userData?.category ?? ''));
          if (categoryMaterial) {
            mesh.material = categoryMaterial;
            return;
          }

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
        onLoaded();
      },
      undefined,
      (loadError) => {
        console.error(loadError);
        onError();
      },
    );
  }

  /** Cached per-category base material, built from the vocabulary SSOT. */
  private baseCategoryMaterialFor(category: string): THREE.MeshStandardMaterial | null {
    const style = BASE_CATEGORY_STYLES[category];
    if (!style) return null;
    let material = this.baseCategoryMaterials.get(category);
    if (!material) {
      const color = new THREE.Color(style.color);
      material = new THREE.MeshStandardMaterial({
        color,
        emissive: style.emissiveScale > 0
          ? color.clone().multiplyScalar(style.emissiveScale)
          : new THREE.Color(0x000000),
        transparent: true,
        opacity: style.opacity,
        roughness: 0.78,
        metalness: 0,
      });
      this.baseCategoryMaterials.set(category, material);
    }
    return material;
  }

  setBaseVisibility(floor: string, layerVisible: boolean): void {
    for (const object of this.baseObjects) {
      const data = object.userData ?? {};
      const floorOk = floor === 'all' || String(data.floor) === floor || data.category === 'elevator';
      object.visible = layerVisible && floorOk;
    }
  }

  /** Cheap per-frame path: token creation/positioning only. */
  updateTokens(states: PatientVisibleState[], layerVisible: boolean, redactIdentity: boolean): void {
    const visible = new Set<string>();
    for (const state of states) {
      let token = this.tokenByPatient.get(state.patientId);
      if (!token) {
        token = new THREE.Mesh(this.tokenGeometry, this.materialForPatient(state.patientId));
        this.tokenByPatient.set(state.patientId, token);
        this.patientLayer.add(token);
      }
      token.position.set(state.position.x, state.position.y, state.position.z);
      token.scale.setScalar(state.event.event_category === 'movement' ? 1 : 0.82);
      token.visible = layerVisible;
      token.userData = {
        kind: 'patient-token',
        ...(redactIdentity
          ? {}
          : {
              patient_id: state.patientId,
              patient_display_id: state.event.patient_display_id,
              encounter_id: state.event.encounter_id,
            }),
        current_location: state.event.to_location,
        service_line: state.event.service_line,
        event_type: state.event.event_type,
        event_category: state.event.event_category,
        last_event_at: state.event.occurred_at,
        recent_event_count: state.recent.length,
      };
      visible.add(state.patientId);
    }

    for (const [patientId, token] of this.tokenByPatient.entries()) {
      if (!visible.has(patientId)) token.visible = false;
    }
  }

  /** Bucketed rebuild: trail polylines up to timeMs for the active patients. */
  rebuildTrails(
    tracks: Map<string, PatientFlowEvent[]>,
    locations: PatientFlowLocations,
    states: PatientVisibleState[],
    timeMs: number,
    layerVisible: boolean,
  ): void {
    this.clearGroup(this.trailLayer);
    if (!layerVisible) return;

    const activePatients = new Set(states.map((state) => state.patientId));
    for (const [patientId, track] of tracks.entries()) {
      if (!activePatients.has(patientId)) continue;
      const points: THREE.Vector3[] = [];
      for (const event of track) {
        if (parseTime(event.occurred_at) > timeMs) break;
        const position = positionFor(locations, event.to_location);
        if (!position) continue;
        const vector = new THREE.Vector3(position.x, position.y, position.z);
        if (!points.length || !points[points.length - 1].equals(vector)) points.push(vector);
      }
      if (points.length < 2) continue;
      const geometry = new THREE.BufferGeometry().setFromPoints(points);
      const line = new THREE.Line(geometry, this.trailMaterialForPatient(patientId));
      line.userData = { kind: 'patient-trail', patient_id: patientId };
      this.trailLayer.add(line);
    }
  }

  /** Bucketed rebuild: overhead occupancy disks with stay/timer state. */
  rebuildHeat(insights: OccupancyInsight[], layerVisible: boolean): number {
    this.clearGroup(this.heatLayer);
    if (!layerVisible) return 0;

    const stackByLocation = new Map<string, number>();

    for (const insight of insights) {
      const stack = stackByLocation.get(insight.location) ?? 0;
      stackByLocation.set(insight.location, stack + 1);
      const stayHours = insight.stayMinutes / 60;
      const radiusScale = Math.min(2.25, 0.78 + Math.sqrt(Math.max(0.25, stayHours)) * 0.2);
      const marker = new THREE.Mesh(this.heatGeometry, this.occupancyMaterialFor(insight.primaryStatus));
      marker.position.set(insight.position.x, insight.position.y + 3.5 + stack * 0.34, insight.position.z);
      marker.scale.set(radiusScale, 1, radiusScale);
      marker.userData = {
        kind: 'occupancy-marker',
        location: insight.location,
        location_name: insight.locationName,
        service_line: insight.serviceLine,
        unit: insight.unitCode,
        stay_duration: formatDurationMinutes(insight.stayMinutes),
        arrived_at: insight.arrivedAt,
        came_from: insight.cameFrom,
        next_move: insight.nextMove,
        next_move_at: insight.nextMoveAt,
        status: insight.primaryStatus,
        blockers: insight.blockers.join(', '),
        barrier_reasons: insight.barrierReasons?.join(' | '),
        barrier_codes: insight.barrierCodes?.join(', '),
        barrier_labels: insight.barrierLabels?.join(' | '),
        owner_roles: insight.ownerRoles?.join(', '),
        delay_impacts: insight.delayImpacts?.join(' | '),
        rtdc_metrics: insight.rtdcMetrics?.join(', '),
        eddy_summaries: insight.eddySummaries?.join(' | '),
        timers: insight.timers.map((timer) => {
          const target = timer.minutesRemaining === null
            ? 'No target'
            : formatRelativeDurationMinutes(timer.minutesRemaining);
          const status = target.toLowerCase().endsWith(timer.status.toLowerCase()) ? '' : ` ${timer.status}`;

          return `${timer.label}: ${target}${status}${timer.reason ? ` because ${timer.reason}` : ''}`;
        }).join(' | '),
        ...Object.fromEntries(
          insight.timers.slice(0, 6).map((timer, index) => [
            `timer_${index + 1}`,
            `${timer.label}${timer.dueAt ? ` due ${timer.dueAt}` : ''}${timer.minutesRemaining !== null ? ` (${formatRelativeDurationMinutes(timer.minutesRemaining)})` : ''} · ${timer.status} · ${timer.source}${timer.reason ? ` · ${timer.reason}` : ''}`,
          ]),
        ),
        ...(insight.patientDisplayId ? { patient_display_id: insight.patientDisplayId } : {}),
        ...(insight.patientId ? { patient_id: insight.patientId } : {}),
        ...(insight.encounterId ? { encounter_id: insight.encounterId } : {}),
        ...(insight.patientContextRef ? { patient_context_ref: insight.patientContextRef } : {}),
      };
      this.heatLayer.add(marker);

      insight.timers.slice(0, 4).forEach((timer, index) => {
        const angle = (index / 4) * Math.PI * 2 + Math.PI / 4;
        const distance = 3.25 * radiusScale;
        const pip = new THREE.Mesh(this.timerPipGeometry, this.timerPipMaterialFor(timer.status));
        pip.position.set(
          insight.position.x + Math.cos(angle) * distance,
          insight.position.y + 3.92 + stack * 0.34,
          insight.position.z + Math.sin(angle) * distance,
        );
        pip.userData = {
          kind: 'occupancy-timer',
          location: insight.location,
          timer: timer.label,
          due_at: timer.dueAt,
          time_to_target: timer.minutesRemaining === null
            ? 'No target'
            : formatRelativeDurationMinutes(timer.minutesRemaining),
          status: timer.status,
          source: timer.source,
          reason: timer.reason,
          barrier_code: timer.barrierCode,
          barrier_label: timer.barrierLabel,
          barrier_category: timer.barrierCategory,
          owner_role: timer.ownerRole,
          blocks: timer.blocks,
          impact: timer.impact,
          rtdc_metrics: timer.rtdcMetrics?.join(', '),
          eddy_summary: timer.eddySummary,
          recommended_focus: timer.recommendedFocus,
          ...(insight.patientDisplayId ? { patient_display_id: insight.patientDisplayId } : {}),
        };
        this.heatLayer.add(pip);
      });
    }

    return stackByLocation.size;
  }

  /**
   * Bucketed rebuild: projection ghost tokens (future half). Translucent
   * spheres, confidence-mapped opacity, provenance in userData for the
   * inspector — never a patient identity (D3).
   */
  rebuildGhosts(ghosts: GhostRenderItem[], layerVisible: boolean): void {
    this.clearGroup(this.ghostLayer);
    if (!layerVisible) return;

    const stackByAnchor = new Map<string, number>();
    for (const ghost of ghosts) {
      const { item, anchor } = ghost;
      const anchorKey = `${anchor.x.toFixed(1)}|${anchor.z.toFixed(1)}`;
      const stack = stackByAnchor.get(anchorKey) ?? 0;
      stackByAnchor.set(anchorKey, stack + 1);

      const mesh = new THREE.Mesh(this.ghostGeometry, this.ghostMaterialFor(item.kind, item.confidence));
      mesh.position.set(anchor.x, anchor.y + 1.2 + stack * 2.4, anchor.z);
      mesh.userData = {
        kind: 'projection-ghost',
        projection_kind: item.kind,
        label: item.label,
        confidence: item.confidence,
        projected_at: item.t,
        ...(item.ends_at ? { ends_at: item.ends_at } : {}),
        ...(item.room ? { room: item.room } : {}),
        ...(item.unit_id !== null ? { unit_id: item.unit_id } : {}),
        ...(item.bed_id !== null ? { bed_id: item.bed_id } : {}),
        ...(item.value !== null ? { value: item.value } : {}),
        ...(item.patient_context_ref ? { patient_context_ref: item.patient_context_ref } : {}),
        ...(item.entity ? { entity: `${item.entity.type} ${item.entity.ref}` } : {}),
        ...(item.derived ? { derived: 'Derived · expected discharge' } : {}),
        source: item.provenance.service,
        reliability: item.provenance.reliability !== null ? String(item.provenance.reliability) : 'n/a',
      };
      this.ghostLayer.add(mesh);
    }
  }

  /** Bucketed rebuild: aggregate forecast heat (predicted census per unit). */
  rebuildForecastHeat(cells: ForecastHeatCell[], layerVisible: boolean): void {
    this.clearGroup(this.forecastLayer);
    if (!layerVisible) return;

    for (const cell of cells) {
      const height = Math.max(1.5, cell.value * 0.3);
      const mesh = new THREE.Mesh(this.forecastGeometry, this.forecastMaterialFor(cell.opacity));
      mesh.position.set(cell.anchor.x, cell.anchor.y + 2 + height / 2, cell.anchor.z);
      mesh.scale.set(1, height, 1);
      mesh.userData = { kind: 'forecast-heat', predicted_census: cell.value };
      this.forecastLayer.add(mesh);
    }
  }

  /**
   * Present-state rebuild: open-barrier markers floating above their unit,
   * stacked when a unit carries more than one. Patient-free (encounter_ref is
   * server-redacted), so the full row rides in userData for the inspector.
   */
  rebuildBarriers(cells: BarrierCell[], layerVisible: boolean): void {
    this.clearGroup(this.barrierLayer);
    if (!layerVisible) return;

    const stackByAnchor = new Map<string, number>();
    for (const cell of cells) {
      const { anchor, severity, barrier } = cell;
      const anchorKey = `${anchor.x.toFixed(1)}|${anchor.z.toFixed(1)}`;
      const stack = stackByAnchor.get(anchorKey) ?? 0;
      stackByAnchor.set(anchorKey, stack + 1);

      const mesh = new THREE.Mesh(this.barrierGeometry, this.barrierMaterialFor(severity));
      mesh.position.set(anchor.x, anchor.y + 9 + stack * 5.2, anchor.z);
      mesh.scale.setScalar(BARRIER_SCALE[severity]);
      mesh.userData = {
        kind: 'barrier',
        severity,
        barrier_id: barrier.barrier_id,
        category: barrier.category,
        status: barrier.status,
        ...(barrier.unit_label ? { unit: barrier.unit_label } : {}),
        ...(barrier.reason_code ? { reason_code: barrier.reason_code } : {}),
        ...(barrier.description ? { description: barrier.description } : {}),
        ...(barrier.owner ? { owner: barrier.owner } : {}),
        ...(barrier.opened_at ? { opened_at: barrier.opened_at } : {}),
      };
      this.barrierLayer.add(mesh);
    }
  }

  /**
   * Rounds overlay rebuild: one flat ring per round stop, colored by round
   * state (never coral — a round state is work, not a breach). Pinned stops
   * scale up; the opaque stop payload rides in userData for the inspector.
   * No patient identifier ever enters this layer (plan §8.1).
   */
  rebuildRounds(cells: RoundStopCell[], route: RoundRouteSegment[], layerVisible: boolean): void {
    this.clearGroup(this.roundsLayer);
    this.clearGroup(this.roundsRouteLayer);
    this.roundStopMeshByUuid.clear();
    // The focused mesh (if any) was just removed with the group; drop the
    // clone so it never dangles. Focus re-applies below without re-flying.
    this.focusedRoundMaterial?.dispose();
    this.focusedRoundMaterial = null;
    this.focusedRoundMesh = null;
    if (!layerVisible) return;

    const stackByAnchor = new Map<string, number>();
    for (const cell of cells) {
      const { anchor, stop } = cell;
      const anchorKey = `${anchor.x.toFixed(1)}|${anchor.z.toFixed(1)}`;
      const stack = stackByAnchor.get(anchorKey) ?? 0;
      stackByAnchor.set(anchorKey, stack + 1);

      const mesh = new THREE.Mesh(this.roundGeometry, this.roundMaterialFor(stop.status, stop.pinned));
      mesh.position.set(anchor.x, anchor.y + 4.5 + stack * 3.4, anchor.z);
      mesh.rotation.x = Math.PI / 2;
      mesh.scale.setScalar(stop.pinned ? 1.25 : 1);
      mesh.userData = {
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
      this.roundsLayer.add(mesh);
      this.roundStopMeshByUuid.set(stop.round_patient_uuid, mesh);

      // R-4: queue-number billboard above the ring; skipped/deferred stay
      // dimmed and unnumbered (they are not part of the walk).
      if (!['skipped', 'deferred'].includes(stop.status)) {
        const sprite = new THREE.Sprite(this.queueSpriteMaterialFor(stop.queue_position));
        sprite.position.set(anchor.x, mesh.position.y + 3.1, anchor.z);
        sprite.scale.set(2.6, 2.6, 1);
        sprite.userData = { ...mesh.userData };
        this.roundsLayer.add(sprite);
      }
    }

    // R-4: itinerary polyline — solid per-floor runs, dashed cross-floor legs.
    for (const segment of route) {
      const points = segment.points.map(
        (point) => new THREE.Vector3(point.x, point.y + 3.4, point.z),
      );
      if (points.length < 2) continue;
      const geometry = new THREE.BufferGeometry().setFromPoints(points);
      const line = new THREE.Line(
        geometry,
        segment.dashed ? this.routeDashedMaterialFor() : this.routeSolidMaterialFor(),
      );
      if (segment.dashed) line.computeLineDistances();
      this.roundsRouteLayer.add(line);
    }

    if (this.focusedRoundStopUuid) {
      this.applyRoundFocus(this.focusedRoundStopUuid, false);
    }
  }

  /** Cached canvas-texture sprite material for a queue number. */
  private queueSpriteMaterialFor(queuePosition: number): THREE.SpriteMaterial {
    let material = this.queueSpriteMaterials.get(queuePosition);
    if (!material) {
      const canvas = document.createElement('canvas');
      canvas.width = 64;
      canvas.height = 64;
      const context = canvas.getContext('2d');
      if (context) {
        context.beginPath();
        context.arc(32, 32, 26, 0, Math.PI * 2);
        context.fillStyle = 'rgba(27, 31, 29, 0.85)';
        context.fill();
        context.lineWidth = 3;
        context.strokeStyle = '#94a3b8';
        context.stroke();
        context.font = '600 30px sans-serif';
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillStyle = '#f3efe5';
        context.fillText(String(queuePosition), 32, 34);
      }
      const texture = new THREE.CanvasTexture(canvas);
      material = new THREE.SpriteMaterial({ map: texture, transparent: true, depthWrite: false });
      this.queueSpriteMaterials.set(queuePosition, material);
    }
    return material;
  }

  private routeSolidMaterialFor(): THREE.LineBasicMaterial {
    if (!this.routeSolidMaterial) {
      this.routeSolidMaterial = new THREE.LineBasicMaterial({
        color: 0x94a3b8,
        transparent: true,
        opacity: 0.28,
      });
    }
    return this.routeSolidMaterial;
  }

  private routeDashedMaterialFor(): THREE.LineDashedMaterial {
    if (!this.routeDashedMaterial) {
      this.routeDashedMaterial = new THREE.LineDashedMaterial({
        color: 0x94a3b8,
        transparent: true,
        opacity: 0.28,
        dashSize: 2.2,
        gapSize: 2.2,
      });
    }
    return this.routeDashedMaterial;
  }

  /**
   * Guided-tour focus: highlight one stop and fly the camera to it. Returns
   * false when the stop is not currently placed (wrong floor / no anchor) so
   * the orchestrator can fall back to the board. Pass null to clear.
   */
  focusRoundStop(roundPatientUuid: string | null): boolean {
    this.focusedRoundStopUuid = roundPatientUuid;
    this.clearRoundFocusMaterial();

    if (roundPatientUuid === null) return true;

    return this.applyRoundFocus(roundPatientUuid);
  }

  private focusedRoundMaterial: THREE.MeshStandardMaterial | null = null;

  private focusedRoundMesh: THREE.Mesh | null = null;

  private clearRoundFocusMaterial(): void {
    if (this.focusedRoundMesh && this.focusedRoundMesh.userData?.kind === 'round-stop') {
      const status = String(this.focusedRoundMesh.userData.status ?? 'queued');
      const pinned = Boolean(this.focusedRoundMesh.userData.pinned);
      this.focusedRoundMesh.material = this.roundMaterialFor(status, pinned);
    }
    this.focusedRoundMaterial?.dispose();
    this.focusedRoundMaterial = null;
    this.focusedRoundMesh = null;
  }

  private applyRoundFocus(roundPatientUuid: string, fly = true): boolean {
    const mesh = this.roundStopMeshByUuid.get(roundPatientUuid);
    if (!mesh) return false;

    // Focused ring gets its own (non-shared) brighter material so the pulse
    // never leaks onto same-status siblings; the clone is disposed on unfocus.
    const base = mesh.material as THREE.MeshStandardMaterial;
    const focused = base.clone();
    focused.emissiveIntensity = 2.4;
    mesh.material = focused;
    this.focusedRoundMaterial = focused;
    this.focusedRoundMesh = mesh;
    if (fly) {
      this.focusOn([{ x: mesh.position.x, y: mesh.position.y, z: mesh.position.z }]);
    }
    return true;
  }

  private roundMaterialFor(status: string, pinned: boolean): THREE.MeshStandardMaterial {
    const colorHex = pinned ? ROUND_PINNED_COLOR : (ROUND_STOP_COLORS[status as keyof typeof ROUND_STOP_COLORS] ?? 0x94a3b8);
    const key = `${colorHex}`;
    let material = this.roundMaterials.get(key);
    if (!material) {
      const color = new THREE.Color(colorHex);
      material = new THREE.MeshStandardMaterial({
        color,
        emissive: color.clone().multiplyScalar(0.28),
        roughness: 0.4,
        metalness: 0,
      });
      this.roundMaterials.set(key, material);
    }
    return material;
  }

  focusOn(points: Array<{ x: number; y: number; z: number }>): void {
    if (!points.length) return;
    const box = new THREE.Box3();
    points.forEach((point) => box.expandByPoint(new THREE.Vector3(point.x, point.y, point.z)));
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const radius = Math.max(size.x, size.y, size.z, 24);
    this.orbit.target.copy(center);
    this.camera.position.set(center.x + radius * 1.35, center.y + radius * 1.05, center.z + radius * 1.35);
    this.orbit.update();
  }

  /** Fly to the current selection; false when nothing is selected (N-6 `F`). */
  focusSelection(): boolean {
    if (!this.selectedMesh) return false;
    const { x, y, z } = this.selectedMesh.position;
    this.focusOn([{ x, y, z }]);
    return true;
  }

  /** Camera pose snapshot for saved views (N-7). */
  getCameraView(): CameraView {
    return {
      position: { x: this.camera.position.x, y: this.camera.position.y, z: this.camera.position.z },
      target: { x: this.orbit.target.x, y: this.orbit.target.y, z: this.orbit.target.z },
    };
  }

  /** Restore a saved camera pose (N-7). */
  setCameraView(view: CameraView): void {
    this.camera.position.set(view.position.x, view.position.y, view.position.z);
    this.orbit.target.set(view.target.x, view.target.y, view.target.z);
    this.orbit.update();
  }

  resetCamera(): void {
    this.camera.position.copy(HOME_POSITION);
    this.orbit.target.copy(HOME_TARGET);
    this.orbit.update();
  }

  dispose(): void {
    this.disposed = true;
    cancelAnimationFrame(this.animationId);
    window.removeEventListener('resize', this.onResize);
    this.renderer.domElement.removeEventListener('pointerdown', this.onPointerDown);
    this.renderer.domElement.removeEventListener('pointermove', this.onPointerMove);
    this.renderer.domElement.removeEventListener('pointerleave', this.onPointerLeave);
    this.clearHover();
    this.clearSelection();
    this.hoverChip.remove();
    this.baseCategoryMaterials.forEach((material) => material.dispose());
    this.baseObjects.forEach((object) => this.disposeObject(object));
    this.clearGroup(this.patientLayer);
    this.clearGroup(this.trailLayer);
    this.clearGroup(this.heatLayer);
    this.clearGroup(this.ghostLayer);
    this.clearGroup(this.forecastLayer);
    this.clearGroup(this.barrierLayer);
    this.clearGroup(this.roundsLayer);
    this.clearGroup(this.roundsRouteLayer);
    this.roundStopMeshByUuid.clear();
    this.queueSpriteMaterials.forEach((material) => {
      material.map?.dispose();
      material.dispose();
    });
    this.routeSolidMaterial?.dispose();
    this.routeDashedMaterial?.dispose();
    this.patientMaterials.forEach((material) => material.dispose());
    this.trailMaterials.forEach((material) => material.dispose());
    this.ghostMaterials.forEach((material) => material.dispose());
    this.forecastMaterials.forEach((material) => material.dispose());
    this.occupancyMaterials.forEach((material) => material.dispose());
    this.timerPipMaterials.forEach((material) => material.dispose());
    this.barrierMaterials.forEach((material) => material.dispose());
    this.roundMaterials.forEach((material) => material.dispose());
    this.heatSingleMaterial.dispose();
    this.heatMultiMaterial.dispose();
    this.tokenGeometry.dispose();
    this.ghostGeometry.dispose();
    this.heatGeometry.dispose();
    this.timerPipGeometry.dispose();
    this.forecastGeometry.dispose();
    this.barrierGeometry.dispose();
    this.roundGeometry.dispose();
    this.orbit.dispose();
    this.renderer.dispose();
  }

  private readonly animate = (): void => {
    if (this.disposed) return;
    const delta = Math.min(this.clock.getDelta(), 0.05);
    this.callbacks.onFrame(delta);
    this.orbit.update();
    this.emitCameraText();
    this.renderer.render(this.scene, this.camera);
    this.animationId = requestAnimationFrame(this.animate);
  };

  /** Throttled — a React state write per frame is exactly the churn we removed. */
  private emitCameraText(): void {
    const now = performance.now();
    if (now - this.lastCameraEmit < 150) return;
    const position = this.camera.position;
    const target = this.orbit.target;
    const key = [position.x, position.y, position.z, target.x, target.y, target.z]
      .map((value) => value.toFixed(0))
      .join('|');
    if (key === this.lastCameraText) return;
    this.lastCameraText = key;
    this.lastCameraEmit = now;
    this.callbacks.onCameraMove({
      position: { x: position.x, y: position.y, z: position.z },
      target: { x: target.x, y: target.y, z: target.z },
    });
  }

  private materialForPatient(patientId: string): THREE.MeshStandardMaterial {
    let material = this.patientMaterials.get(patientId);
    if (!material) {
      const color = hashColor(patientId);
      material = new THREE.MeshStandardMaterial({
        color,
        emissive: color.clone().multiplyScalar(0.22),
        roughness: 0.42,
        metalness: 0,
      });
      this.patientMaterials.set(patientId, material);
    }
    return material;
  }

  private trailMaterialForPatient(patientId: string): THREE.LineBasicMaterial {
    let material = this.trailMaterials.get(patientId);
    if (!material) {
      material = new THREE.LineBasicMaterial({ color: hashColor(patientId), transparent: true, opacity: 0.55 });
      this.trailMaterials.set(patientId, material);
    }
    return material;
  }

  private ghostMaterialFor(kind: string, confidence: string): THREE.MeshStandardMaterial {
    const key = `${kind}|${confidence}`;
    let material = this.ghostMaterials.get(key);
    if (!material) {
      const color = new THREE.Color(GHOST_COLORS[kind] ?? 0x94a3b8);
      material = new THREE.MeshStandardMaterial({
        color,
        emissive: color.clone().multiplyScalar(0.3),
        transparent: true,
        opacity: confidenceOpacity(confidence),
        roughness: 0.6,
        metalness: 0,
        depthWrite: false,
      });
      this.ghostMaterials.set(key, material);
    }
    return material;
  }

  private forecastMaterialFor(opacity: number): THREE.MeshStandardMaterial {
    const key = opacity.toFixed(2);
    let material = this.forecastMaterials.get(key);
    if (!material) {
      material = new THREE.MeshStandardMaterial({
        color: FORECAST_COLOR,
        emissive: 0x0f2f36,
        transparent: true,
        opacity,
        depthWrite: false,
      });
      this.forecastMaterials.set(key, material);
    }
    return material;
  }

  private occupancyMaterialFor(status: OccupancyTimerStatus): THREE.MeshStandardMaterial {
    let material = this.occupancyMaterials.get(status);
    if (!material) {
      const { color, emissive } = OCCUPANCY_STATUS_COLORS[status];
      material = new THREE.MeshStandardMaterial({
        color,
        emissive,
        transparent: true,
        opacity: status === 'ok' ? 0.58 : 0.74,
        roughness: 0.52,
        metalness: 0,
        depthWrite: false,
      });
      this.occupancyMaterials.set(status, material);
    }
    return material;
  }

  private timerPipMaterialFor(status: OccupancyTimerStatus): THREE.MeshStandardMaterial {
    let material = this.timerPipMaterials.get(status);
    if (!material) {
      const color = TIMER_PIP_COLORS[status];
      material = new THREE.MeshStandardMaterial({
        color,
        emissive: color,
        emissiveIntensity: status === 'ok' ? 0.18 : 0.42,
        transparent: true,
        opacity: 0.88,
        depthWrite: false,
      });
      this.timerPipMaterials.set(status, material);
    }
    return material;
  }

  private barrierMaterialFor(severity: BarrierSeverity): THREE.MeshStandardMaterial {
    let material = this.barrierMaterials.get(severity);
    if (!material) {
      const { color, emissive } = BARRIER_COLORS[severity];
      material = new THREE.MeshStandardMaterial({
        color,
        emissive,
        emissiveIntensity: 0.9,
        roughness: 0.35,
        metalness: 0,
      });
      this.barrierMaterials.set(severity, material);
    }
    return material;
  }

  /**
   * Remove children of a group, disposing only per-mesh geometries (trail
   * lines). Shared geometries and all materials are cached (patientMaterials /
   * trailMaterials / ghostMaterials / heat materials) and reused across
   * rebuilds — they are disposed once, in dispose().
   */
  private clearGroup(group: THREE.Group): void {
    // A rebuild that removes the hovered/selected mesh must also release its
    // highlight clone — otherwise the clone dangles and the restore targets a
    // detached mesh.
    if (this.hoveredMesh && this.hoveredMesh.parent === group) this.clearHover();
    if (this.selectedMesh && this.selectedMesh.parent === group) this.clearSelection();
    while (group.children.length) {
      const child = group.children.pop() as THREE.Mesh | undefined;
      if (!child) continue;
      if (child.geometry && child.geometry !== this.tokenGeometry
        && child.geometry !== this.ghostGeometry
        && child.geometry !== this.heatGeometry
        && child.geometry !== this.timerPipGeometry
        && child.geometry !== this.forecastGeometry
        && child.geometry !== this.barrierGeometry
        && child.geometry !== this.roundGeometry) {
        child.geometry.dispose();
      }
    }
    if (group === this.patientLayer) this.tokenByPatient.clear();
  }

  private disposeObject(object: THREE.Object3D): void {
    const mesh = object as THREE.Mesh;
    mesh.geometry?.dispose?.();
    const material = mesh.material;
    if (Array.isArray(material)) {
      material.forEach((item) => item.dispose());
    } else {
      material?.dispose?.();
    }
  }
}

/**
 * Identity color for a patient token/trail. Hue is clamped to 160°–280° (E-3)
 * so a token can never impersonate amber/coral status colors — the clamp
 * itself lives in sceneVocabulary.patientHue with the rest of the grammar.
 */
function hashColor(value: string): THREE.Color {
  return new THREE.Color(`hsl(${patientHue(value)}, 70%, 58%)`);
}
