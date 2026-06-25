import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { PointerLockControls } from 'three/addons/controls/PointerLockControls.js';
import { createIcons, icons } from 'lucide';

createIcons({ icons });

const canvas = document.querySelector('#viewport');
const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, preserveDrawingBuffer: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.setSize(window.innerWidth, window.innerHeight);
renderer.outputColorSpace = THREE.SRGBColorSpace;

const scene = new THREE.Scene();
scene.background = new THREE.Color(0x171817);
scene.fog = new THREE.Fog(0x171817, 140, 420);

const camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 1200);
camera.position.set(92, 95, 155);

const hemi = new THREE.HemisphereLight(0xf6f1e8, 0x3d423e, 2.1);
scene.add(hemi);
const sun = new THREE.DirectionalLight(0xfff4df, 2.0);
sun.position.set(-80, 160, 90);
scene.add(sun);

const grid = new THREE.GridHelper(180, 18, 0x85765e, 0x383b37);
grid.position.y = -0.15;
scene.add(grid);

const orbit = new OrbitControls(camera, renderer.domElement);
orbit.target.set(0, 46, 0);
orbit.enableDamping = true;
orbit.maxPolarAngle = Math.PI * 0.49;
orbit.minDistance = 18;
orbit.maxDistance = 360;

const walk = new PointerLockControls(camera, renderer.domElement);
const raycaster = new THREE.Raycaster();
const pointer = new THREE.Vector2();
const clock = new THREE.Clock();
const keys = new Set();
const allObjects = [];
const categories = new Map();
const services = new Set();
const floors = new Set();
let activeFloor = 'all';
let activeService = 'all';
let traumaOnly = false;
let selected = null;
let modelRoot = null;

const floorSelect = document.querySelector('#floorSelect');
const serviceSelect = document.querySelector('#serviceSelect');
const searchInput = document.querySelector('#searchInput');
const toggles = document.querySelector('#toggles');
const modelCount = document.querySelector('#modelCount');
const statusText = document.querySelector('#statusText');
const cameraText = document.querySelector('#cameraText');
const inspectorTitle = document.querySelector('#inspectorTitle');
const inspectorData = document.querySelector('#inspectorData');
const orbitButton = document.querySelector('#orbitButton');
const walkButton = document.querySelector('#walkButton');
const traumaButton = document.querySelector('#traumaButton');
const resetButton = document.querySelector('#resetButton');

function setStatus(text) {
  statusText.textContent = text;
}

function flattenMetadata(data) {
  const entries = [];
  for (const [key, value] of Object.entries(data || {})) {
    if (value === undefined || value === null || value === '') continue;
    let display = value;
    if (typeof value === 'boolean') display = value ? 'yes' : 'no';
    if (typeof value === 'object') display = JSON.stringify(value);
    entries.push([key.replaceAll('_', ' '), String(display)]);
  }
  return entries.slice(0, 18);
}

function showInspector(object) {
  selected = object;
  if (!object) {
    inspectorTitle.textContent = 'Select an element';
    inspectorData.innerHTML = '';
    return;
  }
  const data = object.userData || {};
  inspectorTitle.textContent = data.name || object.name;
  const rows = flattenMetadata(data);
  inspectorData.innerHTML = rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('');
}

function categoryLabel(category) {
  return category
    .replaceAll('_', ' ')
    .replace(/\b\w/g, char => char.toUpperCase());
}

function addToggle(category) {
  const label = document.createElement('label');
  label.className = 'toggle';
  const input = document.createElement('input');
  input.type = 'checkbox';
  input.checked = true;
  input.dataset.category = category;
  input.addEventListener('change', applyFilters);
  const span = document.createElement('span');
  span.textContent = categoryLabel(category);
  label.append(input, span);
  toggles.append(label);
}

function selectedCategories() {
  return new Set([...toggles.querySelectorAll('input[type="checkbox"]')]
    .filter(input => input.checked)
    .map(input => input.dataset.category));
}

function objectMatchesSearch(object) {
  const query = searchInput.value.trim().toLowerCase();
  if (!query) return true;
  const data = object.userData || {};
  return [object.name, data.code, data.name, data.category, data.service_line, data.unit_code, data.elevator_class]
    .filter(Boolean)
    .some(value => String(value).toLowerCase().includes(query));
}

function applyFilters() {
  const cats = selectedCategories();
  let visibleCount = 0;
  for (const object of allObjects) {
    const data = object.userData || {};
    const floorOk = activeFloor === 'all' || String(data.floor) === activeFloor || data.category === 'elevator';
    const serviceOk = activeService === 'all' || data.service_line === activeService;
    const traumaOk = !traumaOnly || data.trauma_priority || data.elevator_class === 'trauma_priority' || data.flow_class === 'emergency_response' || String(data.code || '').includes('TRAUMA') || String(data.name || '').toLowerCase().includes('trauma');
    const categoryOk = cats.has(data.category);
    const searchOk = objectMatchesSearch(object);
    object.visible = floorOk && serviceOk && traumaOk && categoryOk && searchOk;
    if (object.visible) visibleCount += 1;
  }
  modelCount.textContent = `${visibleCount.toLocaleString()} visible`;
}

function frameObject(object) {
  const box = new THREE.Box3().setFromObject(object);
  const center = box.getCenter(new THREE.Vector3());
  const size = box.getSize(new THREE.Vector3());
  const radius = Math.max(size.x, size.y, size.z, 12);
  orbit.target.copy(center);
  camera.position.set(center.x + radius * 1.8, center.y + radius * 1.3, center.z + radius * 1.8);
  orbit.update();
}

function selectBySearch() {
  const query = searchInput.value.trim().toLowerCase();
  if (!query) {
    applyFilters();
    return;
  }
  const match = allObjects.find(object => {
    const data = object.userData || {};
    return [data.code, data.name, data.unit_code, data.elevator_class]
      .filter(Boolean)
      .some(value => String(value).toLowerCase().includes(query));
  });
  if (match) {
    showInspector(match);
    frameObject(match);
    setStatus(`Focused ${match.userData.code || match.name}`);
  }
  applyFilters();
}

function resetCamera() {
  camera.position.set(92, 95, 155);
  orbit.target.set(0, 46, 0);
  orbit.enabled = true;
  orbit.update();
  setStatus('Camera reset');
}

function enableOrbit() {
  if (document.pointerLockElement) document.exitPointerLock();
  orbit.enabled = true;
  orbitButton.classList.add('active');
  walkButton.classList.remove('active');
  setStatus('Orbit mode');
}

function enableWalk() {
  orbit.enabled = false;
  walk.lock();
  walkButton.classList.add('active');
  orbitButton.classList.remove('active');
  setStatus('Walk mode');
}

function updateCameraText() {
  cameraText.textContent = `x ${camera.position.x.toFixed(0)} y ${camera.position.y.toFixed(0)} z ${camera.position.z.toFixed(0)}`;
}

function loadModel() {
  const loader = new GLTFLoader();
  loader.load('../model/hospital_model.glb', gltf => {
    modelRoot = gltf.scene;
    scene.add(modelRoot);
    modelRoot.traverse(object => {
      if (!object.isMesh) return;
      object.castShadow = false;
      object.receiveShadow = true;
      const data = object.userData || {};
      allObjects.push(object);
      if (data.category) categories.set(data.category, (categories.get(data.category) || 0) + 1);
      if (data.service_line) services.add(data.service_line);
      if (Number.isFinite(data.floor) || typeof data.floor === 'number') floors.add(String(data.floor));
    });
    [...floors].sort((a, b) => Number(a) - Number(b)).forEach(floor => {
      const option = document.createElement('option');
      option.value = floor;
      option.textContent = floor === '99' ? 'Vertical' : `Floor ${floor}`;
      floorSelect.append(option);
    });
    [...services].sort().forEach(service => {
      const option = document.createElement('option');
      option.value = service;
      option.textContent = service.replaceAll('_', ' ');
      serviceSelect.append(option);
    });
    [...categories.keys()].sort().forEach(addToggle);
    modelCount.textContent = `${allObjects.length.toLocaleString()} objects`;
    applyFilters();
    setStatus('Model loaded');
  }, undefined, error => {
    console.error(error);
    setStatus('Model failed to load');
  });
}

function onPointerDown(event) {
  if (!modelRoot || event.target !== renderer.domElement || document.pointerLockElement) return;
  pointer.x = (event.clientX / window.innerWidth) * 2 - 1;
  pointer.y = -(event.clientY / window.innerHeight) * 2 + 1;
  raycaster.setFromCamera(pointer, camera);
  const hits = raycaster.intersectObjects(allObjects.filter(object => object.visible), false);
  if (hits.length) {
    showInspector(hits[0].object);
    setStatus(`Selected ${hits[0].object.userData.code || hits[0].object.name}`);
  }
}

function animate() {
  requestAnimationFrame(animate);
  const delta = Math.min(clock.getDelta(), 0.05);
  if (document.pointerLockElement) {
    const speed = 55 * delta;
    if (keys.has('KeyW')) walk.moveForward(speed);
    if (keys.has('KeyS')) walk.moveForward(-speed);
    if (keys.has('KeyA')) walk.moveRight(-speed);
    if (keys.has('KeyD')) walk.moveRight(speed);
    if (keys.has('KeyQ')) camera.position.y -= speed;
    if (keys.has('KeyE')) camera.position.y += speed;
  } else {
    orbit.update();
  }
  updateCameraText();
  renderer.render(scene, camera);
}

floorSelect.addEventListener('change', event => {
  activeFloor = event.target.value;
  applyFilters();
});
serviceSelect.addEventListener('change', event => {
  activeService = event.target.value;
  applyFilters();
});
searchInput.addEventListener('input', () => {
  window.clearTimeout(searchInput._timer);
  searchInput._timer = window.setTimeout(selectBySearch, 160);
});
orbitButton.addEventListener('click', enableOrbit);
walkButton.addEventListener('click', enableWalk);
traumaButton.addEventListener('click', () => {
  traumaOnly = !traumaOnly;
  traumaButton.classList.toggle('active', traumaOnly);
  applyFilters();
  setStatus(traumaOnly ? 'Trauma path isolated' : 'All paths restored');
});
resetButton.addEventListener('click', resetCamera);
window.addEventListener('pointerdown', onPointerDown);
window.addEventListener('keydown', event => keys.add(event.code));
window.addEventListener('keyup', event => keys.delete(event.code));
window.addEventListener('resize', () => {
  camera.aspect = window.innerWidth / window.innerHeight;
  camera.updateProjectionMatrix();
  renderer.setSize(window.innerWidth, window.innerHeight);
});
document.addEventListener('pointerlockchange', () => {
  if (!document.pointerLockElement) enableOrbit();
});

enableOrbit();
loadModel();
animate();
