import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { createIcons, icons } from 'lucide';

createIcons({ icons });

const canvas = document.querySelector('#viewport');
const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, preserveDrawingBuffer: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.setSize(window.innerWidth, window.innerHeight);
renderer.outputColorSpace = THREE.SRGBColorSpace;

const scene = new THREE.Scene();
scene.background = new THREE.Color(0x121514);
scene.fog = new THREE.Fog(0x121514, 150, 470);

const camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 1400);
camera.position.set(88, 104, 162);

const orbit = new OrbitControls(camera, renderer.domElement);
orbit.target.set(0, 48, 0);
orbit.enableDamping = true;
orbit.maxPolarAngle = Math.PI * 0.49;
orbit.minDistance = 18;
orbit.maxDistance = 380;

scene.add(new THREE.HemisphereLight(0xf6f0e4, 0x343a36, 2.2));
const sun = new THREE.DirectionalLight(0xfff5df, 2.0);
sun.position.set(-85, 170, 80);
scene.add(sun);
const grid = new THREE.GridHelper(190, 19, 0x796e59, 0x333834);
grid.position.y = -0.12;
scene.add(grid);

const patientLayer = new THREE.Group();
const trailLayer = new THREE.Group();
const heatLayer = new THREE.Group();
scene.add(heatLayer, trailLayer, patientLayer);

const tokenGeometry = new THREE.SphereGeometry(1.65, 18, 12);
const heatGeometry = new THREE.CylinderGeometry(1.9, 1.9, 1, 18, 1);
const patientMaterials = new Map();
const raycaster = new THREE.Raycaster();
const pointer = new THREE.Vector2();
const clock = new THREE.Clock();

let modelRoot;
let baseObjects = [];
let events = [];
let tracks = new Map();
let locations = {};
let tokenByPatient = new Map();
let minTime = 0;
let maxTime = 0;
let currentTime = 0;
let playing = false;
let live = false;
let source = null;
let lastVisibleStates = [];

const summaryText = document.querySelector('#summaryText');
const timeLabel = document.querySelector('#timeLabel');
const timeSlider = document.querySelector('#timeSlider');
const playButton = document.querySelector('#playButton');
const liveButton = document.querySelector('#liveButton');
const resetButton = document.querySelector('#resetButton');
const focusButton = document.querySelector('#focusButton');
const floorSelect = document.querySelector('#floorSelect');
const serviceSelect = document.querySelector('#serviceSelect');
const categorySelect = document.querySelector('#categorySelect');
const speedSelect = document.querySelector('#speedSelect');
const searchInput = document.querySelector('#searchInput');
const baseLayer = document.querySelector('#baseLayer');
const tokensLayer = document.querySelector('#tokensLayer');
const trailsLayer = document.querySelector('#trailsLayer');
const heatLayerInput = document.querySelector('#heatLayer');
const activeMetric = document.querySelector('#activeMetric');
const eventMetric = document.querySelector('#eventMetric');
const occupancyMetric = document.querySelector('#occupancyMetric');
const inspectorTitle = document.querySelector('#inspectorTitle');
const inspectorData = document.querySelector('#inspectorData');
const feedList = document.querySelector('#feedList');
const statusText = document.querySelector('#statusText');
const cameraText = document.querySelector('#cameraText');

function setStatus(text) {
  statusText.textContent = text;
}

function hashColor(value) {
  let hash = 0;
  for (let i = 0; i < value.length; i += 1) hash = ((hash << 5) - hash) + value.charCodeAt(i);
  const hue = Math.abs(hash) % 360;
  return new THREE.Color(`hsl(${hue}, 70%, 58%)`);
}

function materialForPatient(patientId) {
  if (!patientMaterials.has(patientId)) {
    const color = hashColor(patientId);
    patientMaterials.set(patientId, new THREE.MeshStandardMaterial({
      color,
      emissive: color.clone().multiplyScalar(0.22),
      roughness: 0.42,
      metalness: 0.0,
    }));
  }
  return patientMaterials.get(patientId);
}

function parseTime(value) {
  return new Date(value).getTime();
}

function fmtTime(ms) {
  return new Date(ms).toLocaleString([], {
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function positionFor(locationCode) {
  const loc = locations[locationCode];
  if (!loc?.position_m) return null;
  return new THREE.Vector3(loc.position_m.x, loc.position_m.y + 1.7, loc.position_m.z);
}

function addOptions(select, values, formatter = value => value) {
  for (const value of values) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = formatter(value);
    select.append(option);
  }
}

function rebuildTracks() {
  tracks = new Map();
  for (const event of events) {
    if (!tracks.has(event.patient_id)) tracks.set(event.patient_id, []);
    tracks.get(event.patient_id).push(event);
  }
  for (const track of tracks.values()) track.sort((a, b) => parseTime(a.occurred_at) - parseTime(b.occurred_at));
}

function addEventToFeed(event) {
  const li = document.createElement('li');
  const time = document.createElement('span');
  const text = document.createElement('span');
  time.textContent = new Date(event.occurred_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  text.textContent = `${event.patient_display_id} ${event.event_type} ${event.to_location || ''}`;
  li.append(time, text);
  feedList.prepend(li);
  while (feedList.children.length > 8) feedList.lastElementChild.remove();
}

function visibleByFilters(event) {
  if (floorSelect.value !== 'all' && String(event.location_floor) !== floorSelect.value) return false;
  if (serviceSelect.value !== 'all' && event.service_line !== serviceSelect.value && event.location_service_line !== serviceSelect.value) return false;
  if (categorySelect.value !== 'all' && event.event_category !== categorySelect.value) return false;
  const query = searchInput.value.trim().toLowerCase();
  if (!query) return true;
  return [event.patient_display_id, event.patient_id, event.to_location, event.service_line, event.location_name, event.unit_code]
    .filter(Boolean)
    .some(value => String(value).toLowerCase().includes(query));
}

function patientStatesAt(timeMs) {
  const states = [];
  const transitionMs = 12 * 60 * 1000;
  for (const [patientId, track] of tracks.entries()) {
    let current = null;
    for (const event of track) {
      const when = parseTime(event.occurred_at);
      if (when > timeMs) break;
      if (event.event_type === 'discharge' || event.event_type === 'cancel_admit') {
        current = null;
        continue;
      }
      if (event.to_location) current = event;
    }
    if (!current || !visibleByFilters(current)) continue;
    const pos = positionFor(current.to_location);
    if (!pos) continue;
    const when = parseTime(current.occurred_at);
    const fromPos = current.from_location ? positionFor(current.from_location) : null;
    let drawPos = pos.clone();
    if (fromPos && timeMs - when >= 0 && timeMs - when <= transitionMs && current.event_category === 'movement') {
      drawPos = fromPos.clone().lerp(pos, (timeMs - when) / transitionMs);
    }
    const recent = track.filter(event => parseTime(event.occurred_at) <= timeMs && parseTime(event.occurred_at) >= timeMs - 2 * 60 * 60 * 1000);
    states.push({ patientId, event: current, position: drawPos, recent });
  }
  return states;
}

function updateBaseVisibility() {
  const floor = floorSelect.value;
  for (const object of baseObjects) {
    const data = object.userData || {};
    const floorOk = floor === 'all' || String(data.floor) === floor || data.category === 'elevator';
    object.visible = baseLayer.checked && floorOk;
  }
}

function clearGroup(group) {
  while (group.children.length) {
    const child = group.children.pop();
    child.geometry?.dispose?.();
    child.material?.dispose?.();
  }
}

function updateTokens(states) {
  const visible = new Set();
  for (const state of states) {
    let token = tokenByPatient.get(state.patientId);
    if (!token) {
      token = new THREE.Mesh(tokenGeometry, materialForPatient(state.patientId));
      token.userData = { kind: 'patient-token' };
      tokenByPatient.set(state.patientId, token);
      patientLayer.add(token);
    }
    token.position.copy(state.position);
    token.scale.setScalar(state.event.event_category === 'movement' ? 1.0 : 0.82);
    token.visible = tokensLayer.checked;
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
  for (const [patientId, token] of tokenByPatient.entries()) {
    if (!visible.has(patientId)) token.visible = false;
  }
}

function updateTrails(states, timeMs) {
  clearGroup(trailLayer);
  if (!trailsLayer.checked) return;
  const activePatients = new Set(states.map(state => state.patientId));
  for (const [patientId, track] of tracks.entries()) {
    if (!activePatients.has(patientId)) continue;
    const points = [];
    for (const event of track) {
      if (parseTime(event.occurred_at) > timeMs) break;
      const pos = positionFor(event.to_location);
      if (pos && (!points.length || !points[points.length - 1].equals(pos))) points.push(pos);
    }
    if (points.length < 2) continue;
    const geometry = new THREE.BufferGeometry().setFromPoints(points);
    const material = new THREE.LineBasicMaterial({ color: hashColor(patientId), transparent: true, opacity: 0.55 });
    const line = new THREE.Line(geometry, material);
    line.userData = { kind: 'patient-trail', patient_id: patientId };
    trailLayer.add(line);
  }
}

function updateHeat(states) {
  clearGroup(heatLayer);
  if (!heatLayerInput.checked) return;
  const occupancy = new Map();
  for (const state of states) {
    const loc = state.event.to_location;
    occupancy.set(loc, (occupancy.get(loc) || 0) + 1);
  }
  for (const [loc, count] of occupancy.entries()) {
    const pos = positionFor(loc);
    if (!pos) continue;
    const material = new THREE.MeshStandardMaterial({
      color: count > 1 ? 0xf06755 : 0x77c06f,
      emissive: count > 1 ? 0x5a140d : 0x143d17,
      transparent: true,
      opacity: 0.62,
    });
    const marker = new THREE.Mesh(heatGeometry, material);
    marker.position.set(pos.x, pos.y + 2 + count * 0.42, pos.z);
    marker.scale.set(1.0 + count * 0.12, Math.max(1, count * 1.2), 1.0 + count * 0.12);
    marker.userData = {
      kind: 'occupancy-marker',
      location: loc,
      active_patient_count: count,
      location_name: locations[loc]?.name,
    };
    heatLayer.add(marker);
  }
  occupancyMetric.textContent = String(occupancy.size);
}

function updateScene() {
  timeLabel.textContent = fmtTime(currentTime);
  const percent = maxTime === minTime ? 10000 : Math.round(((currentTime - minTime) / (maxTime - minTime)) * 10000);
  timeSlider.value = String(Math.max(0, Math.min(10000, percent)));
  updateBaseVisibility();
  const states = patientStatesAt(currentTime);
  lastVisibleStates = states;
  updateTokens(states);
  updateTrails(states, currentTime);
  updateHeat(states);
  activeMetric.textContent = String(states.length);
  eventMetric.textContent = String(events.filter(event => parseTime(event.occurred_at) <= currentTime).length);
}

function showInspector(data, title) {
  inspectorTitle.textContent = title || data.name || data.patient_display_id || 'Selected';
  const rows = Object.entries(data)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .slice(0, 18)
    .map(([key, value]) => {
      const display = typeof value === 'object' ? JSON.stringify(value) : String(value);
      return `<dt>${key.replaceAll('_', ' ')}</dt><dd>${display}</dd>`;
    });
  inspectorData.innerHTML = rows.join('');
}

function frameActivePatients() {
  if (!lastVisibleStates.length) return;
  const box = new THREE.Box3();
  for (const state of lastVisibleStates) box.expandByPoint(state.position);
  const center = box.getCenter(new THREE.Vector3());
  const size = box.getSize(new THREE.Vector3());
  const radius = Math.max(size.x, size.y, size.z, 24);
  orbit.target.copy(center);
  camera.position.set(center.x + radius * 1.35, center.y + radius * 1.05, center.z + radius * 1.35);
  orbit.update();
}

function resetCamera() {
  camera.position.set(88, 104, 162);
  orbit.target.set(0, 48, 0);
  orbit.update();
}

async function bootstrap() {
  const [summary, locationData, eventData] = await Promise.all([
    fetch('/api/summary').then(r => r.json()),
    fetch('/api/locations').then(r => r.json()),
    fetch('/api/events?limit=20000').then(r => r.json()),
  ]);
  locations = locationData;
  events = eventData.sort((a, b) => parseTime(a.occurred_at) - parseTime(b.occurred_at));
  rebuildTracks();
  minTime = parseTime(events[0].occurred_at);
  maxTime = parseTime(events[events.length - 1].occurred_at);
  currentTime = maxTime;
  summaryText.textContent = `${summary.patients} pts / ${summary.normalized_events} events`;
  const floors = [...new Set(Object.values(locations).map(loc => loc.floor).filter(value => value !== undefined))].sort((a, b) => Number(a) - Number(b));
  const services = [...new Set(events.map(event => event.service_line).filter(Boolean))].sort();
  const categories = [...new Set(events.map(event => event.event_category).filter(Boolean))].sort();
  addOptions(floorSelect, floors.map(String), value => `Floor ${value}`);
  addOptions(serviceSelect, services, value => value.replaceAll('_', ' '));
  addOptions(categorySelect, categories, value => value.replaceAll('_', ' '));

  await new Promise((resolve, reject) => {
    new GLTFLoader().load('/cad-model/model/hospital_model.glb', gltf => {
      modelRoot = gltf.scene;
      scene.add(modelRoot);
      modelRoot.traverse(object => {
        if (!object.isMesh) return;
        baseObjects.push(object);
        if (object.material) {
          object.material = object.material.clone();
          object.material.transparent = true;
          object.material.opacity = object.userData?.category === 'floor' ? 0.56 : 0.72;
        }
      });
      resolve();
    }, undefined, reject);
  });
  updateScene();
  setStatus('Model loaded');
}

function connectLive() {
  if (source) source.close();
  source = new EventSource('/stream/adt?replay=180&interval=0.65');
  source.addEventListener('patient-flow', eventMessage => {
    const event = JSON.parse(eventMessage.data);
    events.push(event);
    events.sort((a, b) => parseTime(a.occurred_at) - parseTime(b.occurred_at));
    rebuildTracks();
    maxTime = Math.max(maxTime, parseTime(event.occurred_at));
    currentTime = maxTime;
    addEventToFeed(event);
    updateScene();
  });
  source.onerror = () => setStatus('Live stream reconnecting');
  live = true;
  liveButton.classList.add('active');
  setStatus('Live stream active');
}

function disconnectLive() {
  if (source) source.close();
  source = null;
  live = false;
  liveButton.classList.remove('active');
  setStatus('Historical replay');
}

function animate() {
  requestAnimationFrame(animate);
  const delta = Math.min(clock.getDelta(), 0.05);
  if (playing && !live) {
    const minutesPerSecond = Number(speedSelect.value);
    currentTime += delta * minutesPerSecond * 60 * 1000;
    if (currentTime > maxTime) currentTime = minTime;
    updateScene();
  }
  orbit.update();
  cameraText.textContent = `x ${camera.position.x.toFixed(0)} y ${camera.position.y.toFixed(0)} z ${camera.position.z.toFixed(0)}`;
  renderer.render(scene, camera);
}

timeSlider.addEventListener('input', () => {
  disconnectLive();
  const pct = Number(timeSlider.value) / 10000;
  currentTime = minTime + pct * (maxTime - minTime);
  updateScene();
});
playButton.addEventListener('click', () => {
  playing = !playing;
  playButton.classList.toggle('active', playing);
  playButton.innerHTML = playing ? '<i data-lucide="pause"></i>' : '<i data-lucide="play"></i>';
  createIcons({ icons });
  if (playing) disconnectLive();
});
liveButton.addEventListener('click', () => live ? disconnectLive() : connectLive());
resetButton.addEventListener('click', resetCamera);
focusButton.addEventListener('click', frameActivePatients);
[floorSelect, serviceSelect, categorySelect, searchInput, baseLayer, tokensLayer, trailsLayer, heatLayerInput].forEach(control => {
  control.addEventListener('input', updateScene);
  control.addEventListener('change', updateScene);
});

window.addEventListener('pointerdown', event => {
  if (event.target !== renderer.domElement) return;
  pointer.x = (event.clientX / window.innerWidth) * 2 - 1;
  pointer.y = -(event.clientY / window.innerHeight) * 2 + 1;
  raycaster.setFromCamera(pointer, camera);
  const hits = raycaster.intersectObjects([...patientLayer.children, ...heatLayer.children, ...baseObjects].filter(o => o.visible), false);
  if (!hits.length) return;
  const object = hits[0].object;
  const data = object.userData || {};
  showInspector(data, data.patient_display_id || data.name || data.code || data.kind);
});

window.addEventListener('resize', () => {
  camera.aspect = window.innerWidth / window.innerHeight;
  camera.updateProjectionMatrix();
  renderer.setSize(window.innerWidth, window.innerHeight);
});

bootstrap().catch(error => {
  console.error(error);
  setStatus(`Load failed: ${error.message}`);
});
animate();
