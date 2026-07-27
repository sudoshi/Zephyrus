import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { Text as TroikaText } from 'troika-three-text';
import { buildFlightPath, flightDurationMs } from '@/features/patientFlowNavigator/cameraFlight';
import { parseTime, positionFor } from '@/features/patientFlowNavigator/stateProjection';
import { patientTokenInspectorData } from '@/features/patientFlowNavigator/inspector';
import { confidenceOpacity } from '@/features/patientFlowNavigator/projections';
import type { BarrierCell, BarrierSeverity, ProjectionAnchor } from '@/features/patientFlowNavigator/projections';
import {
  BARRIER_COLORS,
  BASE_CATEGORY_STYLES,
  FORECAST_COLOR,
  GHOST_COLORS,
  OCCUPANCY_STATUS_COLORS,
  PATHWAY_DEVIATION_COLOR,
  PATHWAY_DEVIATION_EMISSIVE,
  ROUND_PINNED_COLOR,
  ROUND_STOP_COLORS,
  TIMER_PIP_COLORS,
  dwellNodeScale,
  patientHue,
  traceGradientRgb,
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

/**
 * The one answer to "what is selected" (H1.3). Kinds with stable ids can be
 * selected from any surface (click, search list, feed, tour) and their
 * highlight survives layer rebuilds; other kinds (ghosts, base meshes) are
 * mesh-only selections that live until the next rebuild.
 */
export interface SelectionEntity {
  kind: 'patient' | 'occupancy' | 'barrier' | 'round-stop';
  id: string;
}

export interface NavigatorSceneCallbacks {
  onSelect: (data: Record<string, unknown>, entity: SelectionEntity | null) => void;
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

  // The ACTIVE camera (raycast, flight, billboards, render all read this).
  // E3 swaps it between the perspective iso camera and a top-down ortho one.
  private camera: THREE.PerspectiveCamera | THREE.OrthographicCamera;

  private perspectiveCamera: THREE.PerspectiveCamera;

  private orthoCamera: THREE.OrthographicCamera;

  private orthoEnabled = false;

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

  // Entity registries (H1.3) — resolve a SelectionEntity back to its current
  // mesh after any rebuild. tokenByPatient/roundStopMeshByUuid double as the
  // patient and round-stop registries.
  private heatMeshByLocation = new Map<string, THREE.Mesh>();

  private barrierMeshById = new Map<string, THREE.Mesh>();

  private selectedEntity: SelectionEntity | null = null;

  private baseCategoryMaterials = new Map<string, THREE.MeshStandardMaterial>();

  private delayedCueMaterial: THREE.SpriteMaterial | null = null;

  private focusedRoundStopUuid: string | null = null;

  // B3 trace mode (plan §7.1, PJ-2): the selected patient's story in-scene.
  private tracePatientId: string | null = null;

  private followPatientId: string | null = null;

  private readonly followDelta = new THREE.Vector3();

  // E2: one active camera flight along the van Wijk arc. Operator input
  // ('start') or a newer flight cancels it; pre-allocated vectors keep the
  // per-frame step allocation-free.
  private flight: {
    startedAt: number;
    duration: number;
    panAt: (t: number) => number;
    widthAt: (t: number) => number;
    fromTarget: THREE.Vector3;
    toTarget: THREE.Vector3;
    fromDir: THREE.Vector3;
    toDir: THREE.Vector3;
  } | null = null;

  private readonly flightScratchTarget = new THREE.Vector3();

  private readonly flightScratchDir = new THREE.Vector3();

  private traceLineMaterial: THREE.LineBasicMaterial | null = null;

  // Phase C (plan §7.2): pathway-deviation glyphs. A hollow amber bracket
  // rides above a deviant patient's token; entries are ptok → worded chip
  // state ("sepsis · late step"). Amber cap is the VOCABULARY's contract.
  private pathwayLayer = new THREE.Group();

  private glyphByPatient = new Map<string, THREE.Mesh>();

  private pathwayDeviations = new Map<string, string>();

  private pathwayEnabled = false;

  // E2: SDF unit-name billboards (troika-three-text). Wayfinding labels, not
  // clickable — kept out of the raycast set. Distance-culled + LOD-scaled and
  // billboarded each frame; hidden entirely in ortho/top overview (E3).
  private unitLabelLayer = new THREE.Group();

  private unitLabels = new Map<string, TroikaText>();

  private unitLabelsEnabled = false;

  private static readonly LABEL_CULL_DISTANCE = 300;

  private static readonly LABEL_NEAR_DISTANCE = 70;

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

  // A hollow SQUARE outline (4-segment ring baked 45°), billboarded — distinct
  // from the filled delayed triangle, the filled barrier diamond, and the flat
  // rounds torus (the CVD pair set is shape-discriminated, AT-3).
  private bracketGeometry = (() => {
    const geometry = new THREE.RingGeometry(0.85, 1.25, 4);
    geometry.rotateZ(Math.PI / 4);
    return geometry;
  })();

  private pathwayMaterial = new THREE.MeshStandardMaterial({
    color: PATHWAY_DEVIATION_COLOR,
    emissive: PATHWAY_DEVIATION_EMISSIVE,
    side: THREE.DoubleSide,
  });

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
    this.perspectiveCamera.aspect = width / height;
    this.perspectiveCamera.updateProjectionMatrix();
    // E3: the ortho frustum re-derives from its framed height on aspect change.
    if (this.orthoEnabled) this.applyOrthoFrustum(this.orthoFrameHeight);
    this.renderer.setSize(width, height);
  };

  // E3: the world-space vertical extent the ortho camera frames; focus/fit
  // updates it, resize re-derives the frustum from it.
  private orthoFrameHeight = 180;

  // Reused per-cast buffers — the 20 Hz hover path must not allocate.
  private raycastScratch: THREE.Object3D[] = [];

  private pointerScratch = new THREE.Vector2();

  /** The one interactive set — pointerdown select and pointermove hover agree. */
  private raycastHit(event: PointerEvent, rect?: DOMRect): THREE.Mesh | null {
    const bounds = rect ?? this.renderer.domElement.getBoundingClientRect();
    this.pointerScratch.set(
      ((event.clientX - bounds.left) / bounds.width) * 2 - 1,
      -((event.clientY - bounds.top) / bounds.height) * 2 + 1,
    );
    this.raycaster.setFromCamera(this.pointerScratch, this.camera);
    const candidates = this.raycastScratch;
    candidates.length = 0;
    const collect = (objects: THREE.Object3D[]): void => {
      for (const object of objects) {
        if (object.visible) candidates.push(object);
      }
    };
    collect(this.roundsLayer.children);
    collect(this.pathwayLayer.children);
    collect(this.barrierLayer.children);
    collect(this.patientLayer.children);
    collect(this.ghostLayer.children);
    collect(this.heatLayer.children);
    collect(this.baseObjects);
    const hits = this.raycaster.intersectObjects(candidates, false);
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
    this.selectedEntity = this.entityForMesh(mesh);
    this.applySelection(mesh);
    this.callbacks.onSelect(this.hitData(mesh), this.selectedEntity);
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

    // One rect read per cast — the canvas fills the container, so the same
    // rect anchors both the raycast and the chip position.
    const rect = this.renderer.domElement.getBoundingClientRect();
    const mesh = this.raycastHit(event, rect);
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
      this.hoverChip.textContent = label;
      // transform keeps chip moves compositor-only (no layout per move).
      this.hoverChip.style.transform = `translate(${event.clientX - rect.left + 14}px, ${event.clientY - rect.top + 12}px)`;
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

    this.perspectiveCamera = new THREE.PerspectiveCamera(60, width / height, 0.1, 1400);
    this.perspectiveCamera.position.copy(HOME_POSITION);
    // E3 ortho camera — frustum sized on toggle; up = −z so north reads "up"
    // in plan view. Far plane clears the fixed high vantage it flies to.
    this.orthoCamera = new THREE.OrthographicCamera(-100, 100, 100, -100, 0.1, 2000);
    this.orthoCamera.up.set(0, 0, -1);
    this.camera = this.perspectiveCamera;

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

    this.scene.add(this.forecastLayer, this.heatLayer, this.trailLayer, this.ghostLayer, this.patientLayer, this.barrierLayer, this.roundsLayer, this.roundsRouteLayer, this.pathwayLayer, this.unitLabelLayer);

    // Tour Auto pauses when the OPERATOR grabs the camera; OrbitControls only
    // dispatches 'start' for real input, never for programmatic flights. The
    // operator's hand also cancels any in-progress flight (E2).
    this.orbit.addEventListener('start', () => {
      this.flight = null;
      this.callbacks.onUserCameraStart?.();
    });

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
   * E-5/H1.3: persistent selection highlight — survives until the next
   * selection or Escape; for registered entity kinds it also survives layer
   * rebuilds (the entity re-resolves to the fresh mesh). Round stops keep
   * their dedicated focus-pulse path.
   */
  private applySelection(mesh: THREE.Mesh): void {
    this.clearSelectionVisual();
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

  /** Release the highlight clone only — the selected ENTITY survives. */
  private clearSelectionVisual(): void {
    if (this.selectedMesh && this.selectedOriginalMaterial) {
      this.selectedMesh.material = this.selectedOriginalMaterial;
    }
    this.selectionMaterial?.dispose();
    this.selectionMaterial = null;
    this.selectedOriginalMaterial = null;
    this.selectedMesh = null;
  }

  /** Full deselect: entity + visual. The single Escape path. */
  clearSelection(): void {
    this.selectedEntity = null;
    this.clearSelectionVisual();
  }

  /** The current stable selection, for view-link snapshots (E5). */
  getSelectedEntity(): SelectionEntity | null {
    return this.selectedEntity;
  }

  /** Stable entity for a hit, or null for mesh-only kinds (ghost/base). */
  private entityForMesh(mesh: THREE.Mesh): SelectionEntity | null {
    const data = mesh.userData ?? {};
    switch (data.kind) {
      case 'patient-token':
        return typeof data.patient_id === 'string' ? { kind: 'patient', id: data.patient_id } : null;
      case 'occupancy-marker':
      case 'occupancy-timer':
        return typeof data.location === 'string' ? { kind: 'occupancy', id: data.location } : null;
      case 'barrier':
        return data.barrier_id !== undefined && data.barrier_id !== null
          ? { kind: 'barrier', id: String(data.barrier_id) }
          : null;
      case 'round-stop':
        return typeof data.round_patient_uuid === 'string'
          ? { kind: 'round-stop', id: data.round_patient_uuid }
          : null;
      // Clicking the bracket selects the PATIENT it flags — the entity
      // registry re-resolves to the token, and the orchestrator opens the
      // journey (with its adherence panel) exactly as a token click would.
      case 'pathway-deviation':
        return typeof data.patient_id === 'string' ? { kind: 'patient', id: data.patient_id } : null;
      default:
        return null;
    }
  }

  private resolveEntityMesh(entity: SelectionEntity): THREE.Mesh | null {
    switch (entity.kind) {
      case 'patient':
        return this.tokenByPatient.get(entity.id) ?? null;
      case 'occupancy':
        return this.heatMeshByLocation.get(entity.id) ?? null;
      case 'barrier':
        return this.barrierMeshById.get(entity.id) ?? null;
      case 'round-stop':
        return this.roundStopMeshByUuid.get(entity.id) ?? null;
      default:
        return null;
    }
  }

  /**
   * Select by entity from any non-pointer surface (search list, feed, tour).
   * Returns false when the entity is not currently resolvable in the scene.
   */
  selectEntity(entity: SelectionEntity | null): boolean {
    this.selectedEntity = entity;
    if (!entity) {
      this.clearSelectionVisual();
      return true;
    }
    const mesh = this.resolveEntityMesh(entity);
    if (!mesh) {
      this.clearSelectionVisual();
      return false;
    }
    this.applySelection(mesh);
    return true;
  }

  /** Re-attach the highlight after a rebuild replaced the selected mesh. */
  private reapplySelection(): void {
    if (!this.selectedEntity || this.selectedMesh) return;
    const mesh = this.resolveEntityMesh(this.selectedEntity);
    if (mesh) this.applySelection(mesh);
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
    const trace = this.tracePatientId;
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
      // Trace mode dims every OTHER token so the story reads (materials are
      // per-patient cached, so this touches exactly one patient each).
      const material = token.material as THREE.MeshStandardMaterial;
      const dimmed = trace !== null && state.patientId !== trace;
      const targetOpacity = dimmed ? 0.22 : 1;
      if (material.transparent !== dimmed || material.opacity !== targetOpacity) {
        material.transparent = dimmed;
        material.opacity = targetOpacity;
      }
      // Shared builder (H1.2): mesh userData and the search/feed selection
      // path must present identical inspector payloads.
      token.userData = patientTokenInspectorData(state, redactIdentity);
      visible.add(state.patientId);

      // Phase C glyph: a hollow amber bracket riding above a deviant token.
      // Billboarded; hidden with the token, in trace mode (others dimmed —
      // the bracket must not out-shout the traced story), and when the
      // Pathway layer is off. userData is state + ptok only, never identity.
      const chip = this.pathwayDeviations.get(state.patientId);
      let glyph = this.glyphByPatient.get(state.patientId);
      if (chip) {
        if (!glyph) {
          glyph = new THREE.Mesh(this.bracketGeometry, this.pathwayMaterial);
          this.glyphByPatient.set(state.patientId, glyph);
          this.pathwayLayer.add(glyph);
        }
        glyph.position.set(state.position.x, state.position.y + 3.1, state.position.z);
        glyph.quaternion.copy(this.camera.quaternion);
        glyph.visible = this.pathwayEnabled && layerVisible
          && (trace === null || state.patientId === trace);
        glyph.userData = { kind: 'pathway-deviation', label: chip, patient_id: state.patientId };
      } else if (glyph) {
        glyph.visible = false;
      }
    }

    for (const [patientId, token] of this.tokenByPatient.entries()) {
      if (!visible.has(patientId)) {
        token.visible = false;
        const glyph = this.glyphByPatient.get(patientId);
        if (glyph) glyph.visible = false;
      }
    }

    // Follow mode (B3): glide the orbit pivot (and camera, preserving the
    // operator's framing) toward the followed token. Pre-allocated vector —
    // zero per-frame allocation. Operator camera input clears follow at the
    // orchestrator level (the 'start' listener), so this never fights a hand.
    if (this.followPatientId) {
      const followed = this.tokenByPatient.get(this.followPatientId);
      if (followed?.visible) {
        this.followDelta.subVectors(followed.position, this.orbit.target);
        if (this.followDelta.lengthSq() > 0.01) {
          this.followDelta.multiplyScalar(0.18);
          this.orbit.target.add(this.followDelta);
          this.camera.position.add(this.followDelta);
        }
      }
    }
  }

  /** Bucketed rebuild: trail polylines up to timeMs for the active patients.
   * In trace mode (B3) only the traced patient renders — a time-gradient
   * line (dim slate → identity hue) with dwell markers where they stayed. */
  rebuildTrails(
    tracks: Map<string, PatientFlowEvent[]>,
    locations: PatientFlowLocations,
    states: PatientVisibleState[],
    timeMs: number,
    layerVisible: boolean,
  ): void {
    this.clearGroup(this.trailLayer);
    if (!layerVisible) return;

    const trace = this.tracePatientId;
    const activePatients = new Set(states.map((state) => state.patientId));
    for (const [patientId, track] of tracks.entries()) {
      if (trace !== null) {
        if (patientId !== trace) continue;
      } else if (!activePatients.has(patientId)) {
        continue;
      }

      const points: THREE.Vector3[] = [];
      const arrivals: number[] = [];
      for (const event of track) {
        const eventMs = parseTime(event.occurred_at);
        if (eventMs > timeMs) break;
        const position = positionFor(locations, event.to_location);
        if (!position) continue;
        const vector = new THREE.Vector3(position.x, position.y, position.z);
        if (!points.length || !points[points.length - 1].equals(vector)) {
          points.push(vector);
          arrivals.push(eventMs);
        }
      }
      if (points.length < 2) continue;

      const geometry = new THREE.BufferGeometry().setFromPoints(points);
      if (trace !== null) {
        const colors = new Float32Array(points.length * 3);
        for (let index = 0; index < points.length; index += 1) {
          const t = points.length === 1 ? 1 : index / (points.length - 1);
          const [r, g, b] = traceGradientRgb(t, patientId);
          colors[index * 3] = r;
          colors[index * 3 + 1] = g;
          colors[index * 3 + 2] = b;
        }
        geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
        const line = new THREE.Line(geometry, this.traceLineMaterialLazy());
        line.userData = { kind: 'patient-trail', patient_id: patientId };
        this.trailLayer.add(line);

        // Dwell markers: arrival→next-arrival span at one spot (the open
        // segment runs to the scrubbed time). Shared token geometry (guarded
        // in clearGroup) + the patient's own cached identity material.
        for (let index = 0; index < points.length; index += 1) {
          const dwellMs = (index < points.length - 1 ? arrivals[index + 1] : timeMs) - arrivals[index];
          const scale = dwellNodeScale(dwellMs / 60_000);
          if (scale <= 0) continue;
          const node = new THREE.Mesh(this.tokenGeometry, this.materialForPatient(patientId));
          node.position.copy(points[index]);
          node.position.y += 0.6;
          node.scale.setScalar(scale * 0.5);
          node.userData = { kind: 'trace-dwell', patient_id: patientId };
          this.trailLayer.add(node);
        }
        continue;
      }

      const line = new THREE.Line(geometry, this.trailMaterialForPatient(patientId));
      line.userData = { kind: 'patient-trail', patient_id: patientId };
      this.trailLayer.add(line);
    }
  }

  /** Trace mode on/off (B3). Caller owns triggering the bucketed rebuild. */
  setTraceMode(patientId: string | null): void {
    this.tracePatientId = patientId;
  }

  /**
   * Phase C: the deviant-patient set for the glyph layer (ptok → worded chip
   * state). Stale glyph meshes are pruned here; positions/visibility apply on
   * the per-frame updateTokens path.
   */
  setPathwayDeviations(entries: Map<string, string>, enabled: boolean): void {
    this.pathwayDeviations = entries;
    this.pathwayEnabled = enabled;
    for (const [patientId, glyph] of this.glyphByPatient.entries()) {
      if (!entries.has(patientId)) {
        this.pathwayLayer.remove(glyph);
        this.glyphByPatient.delete(patientId);
      }
    }
  }

  /** Visible deviation glyphs right now — the soak/urgency census counter. */
  pathwayGlyphCount(): number {
    let count = 0;
    for (const glyph of this.glyphByPatient.values()) {
      if (glyph.visible) count += 1;
    }
    return count;
  }

  /** Camera follows this token during replay; null stops following. */
  setFollowPatient(patientId: string | null): void {
    this.followPatientId = patientId;
  }

  /** Frame the traced patient's whole story (trail + dwell + token). */
  focusTrace(): boolean {
    if (!this.tracePatientId) return this.focusSelection();

    const points: Array<{ x: number; y: number; z: number }> = [];
    for (const child of this.trailLayer.children) {
      if (child.userData?.kind === 'trace-dwell') {
        points.push({ x: child.position.x, y: child.position.y, z: child.position.z });
        continue;
      }
      if ((child as THREE.Line).isLine && child.userData?.patient_id === this.tracePatientId) {
        const positionAttr = (child as THREE.Line).geometry.getAttribute('position');
        for (let index = 0; index < positionAttr.count; index += 1) {
          points.push({ x: positionAttr.getX(index), y: positionAttr.getY(index), z: positionAttr.getZ(index) });
        }
      }
    }
    const token = this.tokenByPatient.get(this.tracePatientId);
    if (token?.visible) {
      points.push({ x: token.position.x, y: token.position.y, z: token.position.z });
    }
    if (!points.length) return this.focusSelection();
    this.focusOn(points);
    return true;
  }

  private traceLineMaterialLazy(): THREE.LineBasicMaterial {
    if (!this.traceLineMaterial) {
      this.traceLineMaterial = new THREE.LineBasicMaterial({ vertexColors: true, transparent: true, opacity: 0.95 });
    }
    return this.traceLineMaterial;
  }

  /** Bucketed rebuild: overhead occupancy disks with stay/timer state. */
  rebuildHeat(insights: OccupancyInsight[], layerVisible: boolean): number {
    this.clearGroup(this.heatLayer);
    this.heatMeshByLocation.clear();
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
      if (!this.heatMeshByLocation.has(insight.location)) {
        this.heatMeshByLocation.set(insight.location, marker);
      }

      // H1.1 CVD-safe cue: delayed earns a SHAPE, not just coral — the
      // green/coral axis collapses under deuteranopia. Triangle echoes the
      // board's AlertTriangle; skipped for ok/watch (urgency stays earned).
      if (insight.primaryStatus === 'delayed') {
        const cue = new THREE.Sprite(this.delayedCueMaterialFor());
        cue.position.set(
          insight.position.x,
          marker.position.y + 1.9,
          insight.position.z,
        );
        cue.scale.set(1.9, 1.9, 1);
        cue.userData = { ...marker.userData };
        this.heatLayer.add(cue);
      }

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

    this.reapplySelection();
    return stackByLocation.size;
  }

  /** Cached triangle-warning sprite material for delayed disks (H1.1). */
  private delayedCueMaterialFor(): THREE.SpriteMaterial {
    if (!this.delayedCueMaterial) {
      const canvas = document.createElement('canvas');
      canvas.width = 64;
      canvas.height = 64;
      const context = canvas.getContext('2d');
      if (context) {
        // Rounded triangle, coral fill, dark exclamation — legible as SHAPE
        // regardless of color perception.
        context.beginPath();
        context.moveTo(32, 6);
        context.lineTo(60, 56);
        context.lineTo(4, 56);
        context.closePath();
        context.fillStyle = '#f06755';
        context.fill();
        context.lineWidth = 3;
        context.strokeStyle = 'rgba(27, 31, 29, 0.9)';
        context.stroke();
        context.fillStyle = 'rgba(27, 31, 29, 0.95)';
        context.fillRect(29, 20, 6, 22);
        context.beginPath();
        context.arc(32, 49, 3.4, 0, Math.PI * 2);
        context.fill();
      }
      this.delayedCueMaterial = new THREE.SpriteMaterial({
        map: new THREE.CanvasTexture(canvas),
        transparent: true,
        depthWrite: false,
      });
    }
    return this.delayedCueMaterial;
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
    this.barrierMeshById.clear();
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
      this.barrierMeshById.set(String(barrier.barrier_id), mesh);
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
    this.reapplySelection();
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
    this.reapplySelection();
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

    // A hover/selection clone on this mesh must be released first — otherwise
    // it would be captured as the "base" and a later clearHover/clearSelection
    // restore would stomp the focus material.
    if (this.hoveredMesh === mesh) this.clearHover();
    if (this.selectedMesh === mesh) this.clearSelection();

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

  // The fixed 3/4 iso direction focusOn has always framed from — preserved so
  // a Focus flight lands on the same view it used to jump to.
  private static readonly ISO_DIR = new THREE.Vector3(1.35, 1.05, 1.35).normalize();

  private static readonly ISO_DISTANCE_SCALE = Math.hypot(1.35, 1.05, 1.35);

  /**
   * Frame a set of points. Animated by default along the van Wijk arc (E2);
   * pass `{ instant: true }` for a hard cut (used where a flight would fight a
   * rebuild). The destination direction stays the classic iso 3/4 framing.
   */
  focusOn(points: Array<{ x: number; y: number; z: number }>, options?: { instant?: boolean }): void {
    if (!points.length) return;
    const box = new THREE.Box3();
    points.forEach((point) => box.expandByPoint(new THREE.Vector3(point.x, point.y, point.z)));
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const radius = Math.max(size.x, size.y, size.z, 24);
    const distance = radius * NavigatorScene.ISO_DISTANCE_SCALE;
    this.flyTo(center, distance, NavigatorScene.ISO_DIR, options);
  }

  /**
   * Move the camera to look at `target` from `dir` at `distance`. Animated
   * along the van Wijk & Nuij optimal pan/zoom arc unless `instant` — long
   * pans arc out for context, short hops stay near-linear (E2).
   */
  flyTo(
    target: THREE.Vector3,
    distance: number,
    dir: THREE.Vector3,
    options?: { instant?: boolean },
  ): void {
    // E3: in plan view there is no arc — reframe the ortho camera straight
    // down, mapping the requested framing distance to the frustum height.
    if (this.orthoEnabled) {
      this.frameOrtho(target.clone(), Math.max(40, distance));
      return;
    }
    const toDir = dir.clone().normalize();
    if (options?.instant) {
      this.flight = null;
      this.orbit.target.copy(target);
      this.camera.position.copy(target).addScaledVector(toDir, distance);
      this.orbit.update();
      return;
    }

    const fromTarget = this.orbit.target.clone();
    const fromDir = this.flightScratchDir.subVectors(this.camera.position, this.orbit.target);
    const fromDistance = Math.max(this.orbit.minDistance, fromDir.length());
    fromDir.normalize();

    const panDistance = fromTarget.distanceTo(target);
    const path = buildFlightPath(panDistance, fromDistance, distance);
    // Degenerate (already there) — no flight, just settle.
    if (path.pathLength < 1e-4) {
      this.flight = null;
      this.orbit.target.copy(target);
      this.camera.position.copy(target).addScaledVector(toDir, distance);
      this.orbit.update();
      return;
    }

    this.flight = {
      startedAt: performance.now(),
      duration: flightDurationMs(path.pathLength),
      panAt: path.panAt,
      widthAt: path.widthAt,
      fromTarget,
      toTarget: target.clone(),
      fromDir: fromDir.clone(),
      toDir,
    };
  }

  /** Advance an active flight; returns true while one is running (animate). */
  private stepFlight(): boolean {
    const flight = this.flight;
    if (!flight) return false;
    const elapsed = performance.now() - flight.startedAt;
    const t = Math.min(1, elapsed / flight.duration);

    // Pan along the straight target line; smoothstep the reorientation so a
    // direction change eases rather than tracks the (non-linear) pan param.
    const pan = flight.panAt(t);
    const width = flight.widthAt(t);
    const ease = t * t * (3 - 2 * t);

    this.flightScratchTarget.copy(flight.fromTarget).lerp(flight.toTarget, pan);
    this.flightScratchDir.copy(flight.fromDir).lerp(flight.toDir, ease);
    if (this.flightScratchDir.lengthSq() < 1e-6) this.flightScratchDir.copy(flight.toDir);
    this.flightScratchDir.normalize();

    this.orbit.target.copy(this.flightScratchTarget);
    this.camera.position.copy(this.flightScratchTarget).addScaledVector(this.flightScratchDir, width);

    if (t >= 1) this.flight = null;
    return true;
  }

  /** True while a programmatic flight is in progress (tests / soak). */
  isFlying(): boolean {
    return this.flight !== null;
  }

  /**
   * E2: place SDF unit-name billboards at unit anchors. Rebuilds the label set
   * only when the anchor list changes (cheap: ~20 units); positioning,
   * billboarding, LOD scale, and distance culling happen per frame in
   * updateUnitLabels. `enabled` gates the whole layer (persona/ortho off).
   */
  setUnitLabels(labels: Array<{ id: string; text: string; position: { x: number; y: number; z: number } }>, enabled: boolean): void {
    this.unitLabelsEnabled = enabled;
    const seen = new Set<string>();
    for (const { id, text, position } of labels) {
      seen.add(id);
      let label = this.unitLabels.get(id);
      if (!label) {
        label = new TroikaText();
        label.font = undefined; // troika's bundled default (Roboto SDF) — no network fetch
        label.fontSize = 4.2;
        label.anchorX = 'center';
        label.anchorY = 'middle';
        label.color = 0xece7dc;
        label.outlineWidth = 0.18;
        label.outlineColor = 0x121514;
        label.material.depthWrite = false;
        label.material.transparent = true;
        this.unitLabels.set(id, label);
        this.unitLabelLayer.add(label);
      }
      if (label.text !== text) {
        label.text = text;
        label.sync();
      }
      label.userData.anchor = position;
    }
    // Drop labels for units no longer present.
    for (const [id, label] of this.unitLabels.entries()) {
      if (!seen.has(id)) {
        this.unitLabelLayer.remove(label);
        label.dispose();
        this.unitLabels.delete(id);
      }
    }
  }

  /** Per-frame billboard + LOD + distance-cull for the unit labels (E2). */
  private updateUnitLabels(): void {
    if (this.unitLabels.size === 0) return;
    const show = this.unitLabelsEnabled;
    for (const label of this.unitLabels.values()) {
      const anchor = label.userData.anchor as { x: number; y: number; z: number } | undefined;
      if (!show || !anchor) {
        label.visible = false;
        continue;
      }
      label.position.set(anchor.x, anchor.y + 6.5, anchor.z);
      const distance = this.camera.position.distanceTo(label.position);
      if (distance > NavigatorScene.LABEL_CULL_DISTANCE) {
        label.visible = false;
        continue;
      }
      label.visible = true;
      label.quaternion.copy(this.camera.quaternion);
      // LOD: hold true size when close, grow slightly with distance so far
      // labels stay legible without dominating; fade the farthest third.
      const near = NavigatorScene.LABEL_NEAR_DISTANCE;
      const far = NavigatorScene.LABEL_CULL_DISTANCE;
      const scale = distance <= near ? 1 : 1 + ((distance - near) / (far - near)) * 0.9;
      label.scale.setScalar(scale);
      const fadeStart = far * 0.72;
      label.material.opacity = distance <= fadeStart
        ? 1
        : Math.max(0, 1 - (distance - fadeStart) / (far - fadeStart));
    }
  }

  // Straight-down direction for the Top canonical view — a hair of −z keeps
  // OrbitControls out of the pole singularity while reading as plan-view.
  private static readonly TOP_DIR = new THREE.Vector3(0, 1, 0.0001).normalize();

  /**
   * E2 canonical "Top" view: frame the points from directly overhead (plan
   * view) so structure and spread read without perspective foreshortening.
   */
  focusTopDown(points: Array<{ x: number; y: number; z: number }>, options?: { instant?: boolean }): void {
    if (!points.length) return;
    const box = new THREE.Box3();
    points.forEach((point) => box.expandByPoint(new THREE.Vector3(point.x, point.y, point.z)));
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const spread = Math.max(size.x, size.z, 24);
    this.flyTo(center, spread * 1.8, NavigatorScene.TOP_DIR, options);
  }

  /** Fly to the current selection; false when nothing is selected (N-6 `F`). */
  focusSelection(): boolean {
    const mesh = this.selectedMesh
      ?? (this.selectedEntity ? this.resolveEntityMesh(this.selectedEntity) : null);
    if (!mesh) return false;
    const { x, y, z } = mesh.position;
    this.focusOn([{ x, y, z }]);
    return true;
  }

  /** Camera pose snapshot for saved views / view links (N-7/E5). Always the
   *  perspective pose — bookmarks and links are perspective-framed. */
  getCameraView(): CameraView {
    const position = this.perspectiveCamera.position;
    return {
      position: { x: position.x, y: position.y, z: position.z },
      target: { x: this.orbit.target.x, y: this.orbit.target.y, z: this.orbit.target.z },
    };
  }

  /** Restore a saved camera pose (N-7). Exits ortho — bookmarks are iso. */
  setCameraView(view: CameraView): void {
    if (this.orthoEnabled) this.setOrthographic(false);
    this.perspectiveCamera.position.set(view.position.x, view.position.y, view.position.z);
    this.orbit.target.set(view.target.x, view.target.y, view.target.z);
    this.orbit.update();
  }

  resetCamera(): void {
    this.flight = null;
    // Home is the panic key — always land in the perspective iso overview.
    if (this.orthoEnabled) this.setOrthographic(false);
    this.perspectiveCamera.position.copy(HOME_POSITION);
    this.orbit.target.copy(HOME_TARGET);
    this.orbit.update();
  }

  /** E2 canonical "House" view: fly (arc) to the default iso overview. */
  flyToHome(options?: { instant?: boolean }): void {
    if (this.orthoEnabled) {
      this.frameOrtho(HOME_TARGET.clone(), this.orthoFrameHeight);
      return;
    }
    const dir = HOME_POSITION.clone().sub(HOME_TARGET);
    this.flyTo(HOME_TARGET.clone(), dir.length(), dir.normalize(), options);
  }

  /**
   * E3: toggle the top-down orthographic plan view. Ortho reads structure and
   * spread without perspective foreshortening — locked straight-down, pan +
   * zoom only. The active camera swaps beneath OrbitControls, raycast, flight,
   * and billboards (all read `this.camera`); the perspective iso view restores
   * on toggle-off. Returns the new state.
   */
  setOrthographic(enabled: boolean): boolean {
    if (enabled === this.orthoEnabled) return this.orthoEnabled;
    this.flight = null;
    const target = this.orbit.target.clone();

    if (enabled) {
      // Frame the same vertical extent the perspective view currently shows.
      const distance = this.perspectiveCamera.position.distanceTo(target);
      const height = 2 * distance * Math.tan((this.perspectiveCamera.fov * Math.PI) / 180 / 2);
      this.camera = this.orthoCamera;
      this.orbit.object = this.orthoCamera;
      this.orbit.minPolarAngle = 0;
      this.orbit.maxPolarAngle = 0; // locked straight down (plan view)
      this.frameOrtho(target, Math.max(40, height));
    } else {
      // Restore the iso perspective looking at the same target, distance
      // recovered from the ortho frame height so the scale barely jumps.
      const distance = this.orthoFrameHeight / (2 * Math.tan((this.perspectiveCamera.fov * Math.PI) / 180 / 2));
      this.camera = this.perspectiveCamera;
      this.orbit.object = this.perspectiveCamera;
      this.orbit.minPolarAngle = 0;
      this.orbit.maxPolarAngle = Math.PI * 0.49;
      this.perspectiveCamera.position.copy(target).addScaledVector(NavigatorScene.ISO_DIR, distance);
      this.orbit.target.copy(target);
      this.orbit.update();
    }

    this.orthoEnabled = enabled;
    return this.orthoEnabled;
  }

  isOrthographic(): boolean {
    return this.orthoEnabled;
  }

  /** Point the ortho camera straight down at `target`, framing `height`. */
  private frameOrtho(target: THREE.Vector3, height: number): void {
    this.orbit.target.copy(target);
    // A fixed high vantage — ortho scale is frustum-based, not distance-based,
    // so any height that clears the scene works; the tiny +z avoids the pole.
    this.orthoCamera.position.set(target.x, target.y + 800, target.z + 0.001);
    this.applyOrthoFrustum(height);
    this.orbit.update();
  }

  /** Size the ortho frustum to frame `height` world units at the current aspect. */
  private applyOrthoFrustum(height: number): void {
    this.orthoFrameHeight = height;
    const aspect = this.container.clientWidth / Math.max(1, this.container.clientHeight);
    const halfH = height / 2;
    const halfW = halfH * aspect;
    this.orthoCamera.left = -halfW;
    this.orthoCamera.right = halfW;
    this.orthoCamera.top = halfH;
    this.orthoCamera.bottom = -halfH;
    this.orthoCamera.updateProjectionMatrix();
  }

  /** Renderer memory/draw counters for the H4 soak hook (soakHook.ts). */
  debugInfo(): { geometries: number; textures: number; calls: number; triangles: number } {
    const { memory, render } = this.renderer.info;
    return {
      geometries: memory.geometries,
      textures: memory.textures,
      calls: render.calls,
      triangles: render.triangles,
    };
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
    this.traceLineMaterial?.dispose();
    this.baseObjects.forEach((object) => this.disposeObject(object));
    this.clearGroup(this.patientLayer);
    this.clearGroup(this.trailLayer);
    this.clearGroup(this.heatLayer);
    this.clearGroup(this.ghostLayer);
    this.clearGroup(this.forecastLayer);
    this.clearGroup(this.barrierLayer);
    this.clearGroup(this.roundsLayer);
    this.clearGroup(this.roundsRouteLayer);
    this.clearGroup(this.pathwayLayer);
    this.glyphByPatient.clear();
    // troika Text owns its own GPU resources — dispose each, don't route
    // through clearGroup (which only handles Mesh geometry).
    this.unitLabels.forEach((label) => {
      this.unitLabelLayer.remove(label);
      label.dispose();
    });
    this.unitLabels.clear();
    this.roundStopMeshByUuid.clear();
    this.queueSpriteMaterials.forEach((material) => {
      material.map?.dispose();
      material.dispose();
    });
    this.routeSolidMaterial?.dispose();
    this.routeDashedMaterial?.dispose();
    this.delayedCueMaterial?.map?.dispose();
    this.delayedCueMaterial?.dispose();
    this.heatMeshByLocation.clear();
    this.barrierMeshById.clear();
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
    this.bracketGeometry.dispose();
    this.pathwayMaterial.dispose();
    this.orbit.dispose();
    this.renderer.dispose();
  }

  private readonly animate = (): void => {
    if (this.disposed) return;
    const delta = Math.min(this.clock.getDelta(), 0.05);
    this.callbacks.onFrame(delta);
    // E2: advance any active van Wijk flight before OrbitControls damps —
    // the flight owns target+position this frame, damping then settles.
    this.stepFlight();
    this.orbit.update();
    this.updateUnitLabels();
    this.emitCameraText();
    this.renderer.render(this.scene, this.camera);
    this.animationId = requestAnimationFrame(this.animate);
  };

  /** Throttled — a React state write per frame is exactly the churn we removed. */
  private emitCameraText(): void {
    const now = performance.now();
    if (now - this.lastCameraEmit < 150) return;
    // Re-arm BEFORE the change check so an idle camera costs one comparison
    // per 150 ms, not string-building work on every animation frame.
    this.lastCameraEmit = now;
    const position = this.camera.position;
    const target = this.orbit.target;
    const key = `${position.x.toFixed(0)}|${position.y.toFixed(0)}|${position.z.toFixed(0)}|${target.x.toFixed(0)}|${target.y.toFixed(0)}|${target.z.toFixed(0)}`;
    if (key === this.lastCameraText) return;
    this.lastCameraText = key;
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
    // Visual only: the selected ENTITY survives the rebuild and re-resolves.
    if (this.selectedMesh && this.selectedMesh.parent === group) this.clearSelectionVisual();
    while (group.children.length) {
      const child = group.children.pop() as THREE.Mesh | undefined;
      if (!child) continue;
      // Sprites share three's module-singleton quad geometry — disposing it
      // would deallocate a live GPU resource for every sprite in the app.
      if ((child as unknown as THREE.Sprite).isSprite) continue;
      if (child.geometry && child.geometry !== this.tokenGeometry
        && child.geometry !== this.ghostGeometry
        && child.geometry !== this.heatGeometry
        && child.geometry !== this.timerPipGeometry
        && child.geometry !== this.forecastGeometry
        && child.geometry !== this.barrierGeometry
        && child.geometry !== this.roundGeometry
        && child.geometry !== this.bracketGeometry) {
        child.geometry.dispose();
      }
    }
    if (group === this.patientLayer) {
      this.tokenByPatient.clear();
      // Glyphs exist only as riders on tokens — clearing the token registry
      // without clearing theirs would leave brackets floating over nothing.
      this.clearGroup(this.pathwayLayer);
      this.glyphByPatient.clear();
    }
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
