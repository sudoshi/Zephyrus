import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { parseTime, positionFor } from '@/features/patientFlowNavigator/stateProjection';
import { confidenceOpacity } from '@/features/patientFlowNavigator/projections';
import type { BarrierCell, BarrierSeverity, ProjectionAnchor } from '@/features/patientFlowNavigator/projections';
import type {
  PatientFlowEvent,
  PatientFlowLocations,
  PatientVisibleState,
  ProjectionItem,
} from '@/features/patientFlowNavigator/types';

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

export interface NavigatorSceneCallbacks {
  onSelect: (data: Record<string, unknown>) => void;
  onCameraMove: (text: string) => void;
  onFrame: (deltaSeconds: number) => void;
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

/** Future-half palette — cool operational tones only, never coral (§5). */
const GHOST_COLORS: Record<string, number> = {
  expected_discharge: 0x2dd4bf, // teal
  transport_due: 0x38bdf8, // sky
  evs_due: 0x7dd3fc, // light sky
  scheduled_or_case: 0x60a5fa, // blue
};

/**
 * Open-barrier marker palette — the earned-urgency ration (watch sky / warning
 * amber / critical coral), the same status language the 48h Review map paints.
 * Coral is reserved for a barrier standing open past 48h — a real breach.
 */
const BARRIER_COLORS: Record<BarrierSeverity, { color: number; emissive: number }> = {
  critical: { color: 0xf06755, emissive: 0x5a140d }, // coral
  warning: { color: 0xeaa640, emissive: 0x4a2e08 }, // amber
  watch: { color: 0x38bdf8, emissive: 0x0c3a4d }, // sky
};

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

  private baseObjects: THREE.Object3D[] = [];

  private tokenByPatient = new Map<string, THREE.Mesh>();

  private patientMaterials = new Map<string, THREE.MeshStandardMaterial>();

  private trailMaterials = new Map<string, THREE.LineBasicMaterial>();

  private ghostMaterials = new Map<string, THREE.MeshStandardMaterial>();

  private forecastMaterials = new Map<string, THREE.MeshStandardMaterial>();

  private barrierMaterials = new Map<BarrierSeverity, THREE.MeshStandardMaterial>();

  private heatSingleMaterial: THREE.MeshStandardMaterial;

  private heatMultiMaterial: THREE.MeshStandardMaterial;

  private tokenGeometry = new THREE.SphereGeometry(1.65, 18, 12);

  private ghostGeometry = new THREE.SphereGeometry(1.45, 14, 10);

  private heatGeometry = new THREE.CylinderGeometry(1.9, 1.9, 1, 18, 1);

  private forecastGeometry = new THREE.CylinderGeometry(2.6, 2.6, 1, 18, 1);

  // A diamond, distinct from every other layer's shape — a barrier reads as a
  // marker, not census/forecast volume.
  private barrierGeometry = new THREE.OctahedronGeometry(2.1);

  private raycaster = new THREE.Raycaster();

  private clock = new THREE.Clock();

  private animationId = 0;

  private lastCameraText = '';

  private lastCameraEmit = 0;

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

  private readonly onPointerDown = (event: PointerEvent): void => {
    if (event.target !== this.renderer.domElement) return;
    const rect = this.renderer.domElement.getBoundingClientRect();
    const pointer = new THREE.Vector2(
      ((event.clientX - rect.left) / rect.width) * 2 - 1,
      -((event.clientY - rect.top) / rect.height) * 2 + 1,
    );
    this.raycaster.setFromCamera(pointer, this.camera);
    const hits = this.raycaster.intersectObjects(
      [
        ...this.barrierLayer.children,
        ...this.patientLayer.children,
        ...this.ghostLayer.children,
        ...this.heatLayer.children,
        ...this.baseObjects,
      ].filter((object) => object.visible),
      false,
    );
    if (!hits.length) return;
    this.callbacks.onSelect({ ...(hits[0].object.userData ?? {}) });
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

    this.scene.add(new THREE.HemisphereLight(0xf6f0e4, 0x343a36, 2.2));
    const sun = new THREE.DirectionalLight(0xfff5df, 2);
    sun.position.set(-85, 170, 80);
    this.scene.add(sun);
    const grid = new THREE.GridHelper(190, 19, 0x796e59, 0x333834);
    grid.position.y = -0.12;
    this.scene.add(grid);

    this.scene.add(this.forecastLayer, this.heatLayer, this.trailLayer, this.ghostLayer, this.patientLayer, this.barrierLayer);

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

    window.addEventListener('resize', this.onResize);
    this.renderer.domElement.addEventListener('pointerdown', this.onPointerDown);
    this.animationId = requestAnimationFrame(this.animate);
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

  /** Bucketed rebuild: census-heat pillars. Returns occupied-location count. */
  rebuildHeat(states: PatientVisibleState[], locations: PatientFlowLocations, layerVisible: boolean): number {
    this.clearGroup(this.heatLayer);
    if (!layerVisible) return 0;

    const occupancy = new Map<string, number>();
    for (const state of states) {
      const loc = state.event.to_location;
      if (loc) occupancy.set(loc, (occupancy.get(loc) ?? 0) + 1);
    }

    for (const [loc, count] of occupancy.entries()) {
      const position = positionFor(locations, loc);
      if (!position) continue;
      const marker = new THREE.Mesh(this.heatGeometry, count > 1 ? this.heatMultiMaterial : this.heatSingleMaterial);
      marker.position.set(position.x, position.y + 2 + count * 0.42, position.z);
      marker.scale.set(1 + count * 0.12, Math.max(1, count * 1.2), 1 + count * 0.12);
      marker.userData = {
        kind: 'occupancy-marker',
        location: loc,
        active_patient_count: count,
        location_name: locations[loc]?.name,
      };
      this.heatLayer.add(marker);
    }

    return occupancy.size;
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
    this.baseObjects.forEach((object) => this.disposeObject(object));
    this.clearGroup(this.patientLayer);
    this.clearGroup(this.trailLayer);
    this.clearGroup(this.heatLayer);
    this.clearGroup(this.ghostLayer);
    this.clearGroup(this.forecastLayer);
    this.clearGroup(this.barrierLayer);
    this.patientMaterials.forEach((material) => material.dispose());
    this.trailMaterials.forEach((material) => material.dispose());
    this.ghostMaterials.forEach((material) => material.dispose());
    this.forecastMaterials.forEach((material) => material.dispose());
    this.barrierMaterials.forEach((material) => material.dispose());
    this.heatSingleMaterial.dispose();
    this.heatMultiMaterial.dispose();
    this.tokenGeometry.dispose();
    this.ghostGeometry.dispose();
    this.heatGeometry.dispose();
    this.forecastGeometry.dispose();
    this.barrierGeometry.dispose();
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
    const text = `x ${this.camera.position.x.toFixed(0)} y ${this.camera.position.y.toFixed(0)} z ${this.camera.position.z.toFixed(0)}`;
    if (text === this.lastCameraText) return;
    this.lastCameraText = text;
    this.lastCameraEmit = now;
    this.callbacks.onCameraMove(text);
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
        color: 0x64bfd0,
        emissive: 0x0f2f36,
        transparent: true,
        opacity,
        depthWrite: false,
      });
      this.forecastMaterials.set(key, material);
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
    while (group.children.length) {
      const child = group.children.pop() as THREE.Mesh | undefined;
      if (!child) continue;
      if (child.geometry && child.geometry !== this.tokenGeometry
        && child.geometry !== this.ghostGeometry
        && child.geometry !== this.heatGeometry
        && child.geometry !== this.forecastGeometry
        && child.geometry !== this.barrierGeometry) {
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

function hashColor(value: string): THREE.Color {
  let hash = 0;
  for (let index = 0; index < value.length; index += 1) {
    hash = ((hash << 5) - hash) + value.charCodeAt(index);
  }
  return new THREE.Color(`hsl(${Math.abs(hash) % 360}, 70%, 58%)`);
}
