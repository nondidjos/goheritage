/**
 * GoHéritage — minimal point-cloud viewer
 *
 * A deliberately small companion to viewer.js: same three.js stack (loaded
 * via the page's importmap) and the same camera/controls/render-loop
 * patterns, but it renders a THREE.Points cloud instead of a textured mesh.
 *
 * Mounts onto #gh-pointcloud-viewer and reads:
 *   data-src    — URL of the point-cloud file (.ply or .pcd)
 *   data-format — the file extension ("ply" | "pcd")
 *
 * Big octree-streamed clouds still belong in an external Potree viewer
 * (set via the "Viewer externe" field); this handles the common case of a
 * single uploaded PLY/PCD so the dataset is visible straight in the panel.
 */

import * as THREE from 'three';
import { PLYLoader } from 'three/addons/loaders/PLYLoader.js';
import { PCDLoader } from 'three/addons/loaders/PCDLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

// Scanner exports (CloudCompare e57/PLY, survey data) are Z-up; Three.js is
// Y-up. Same -90° X rotation the OBJ/GLB pipeline applies in viewer.js.
const Z_UP_FIX = -Math.PI / 2;

// Vertex-colour boost. Scan colours read dim on the dark stage (they carry
// indoor exposure + sRGB-as-linear loss), so lift them a touch. >1 components
// are fine: the shader multiplies before output encoding.
const COLOR_BOOST = 1.35;

// Round-point sprite: a soft white disc on transparent. Combined with
// alphaTest it turns the default square GL_POINT into a clean circle with
// no transparency-sort artefacts (cut pixels are discarded, depth stays
// correct). Built once, shared across materials.
let _circleTex = null;
function circleTexture() {
  if (_circleTex) return _circleTex;
  const S = 64;
  const c = document.createElement('canvas');
  c.width = c.height = S;
  const ctx = c.getContext('2d');
  const r = S / 2;
  const g = ctx.createRadialGradient(r, r, 0, r, r, r);
  g.addColorStop(0.0, 'rgba(255,255,255,1)');
  g.addColorStop(0.7, 'rgba(255,255,255,1)');
  g.addColorStop(0.85, 'rgba(255,255,255,0.9)');
  g.addColorStop(1.0, 'rgba(255,255,255,0)');
  ctx.fillStyle = g;
  ctx.beginPath();
  ctx.arc(r, r, r, 0, Math.PI * 2);
  ctx.fill();
  _circleTex = new THREE.CanvasTexture(c);
  _circleTex.colorSpace = THREE.SRGBColorSpace;
  return _circleTex;
}

// Apply the circle sprite + alpha cutout to a PointsMaterial in place.
function makeRound(material) {
  material.map = circleTexture();
  material.alphaTest = 0.5;   // discard the square corners
  material.transparent = false;
  material.needsUpdate = true;
}

function initPointCloud(container) {
  const src = container.dataset.src;
  const format = (container.dataset.format || 'ply').toLowerCase();
  if (!src) return;

  // The loader + control overlays are absolutely positioned; if the host
  // container is static they'd centre against some ancestor instead (the
  // "off-centre loading bar" bug when the page renders without the side
  // panel). Force a positioning context unconditionally.
  if (getComputedStyle(container).position === 'static') {
    container.style.position = 'relative';
  }

  // Touch-primary devices only (phones/tablets) — not touch laptops.
  const isMobile = window.matchMedia('(hover: none) and (pointer: coarse)').matches
                || window.innerWidth <= 768;

  // ── Renderer ──────────────────────────────────────────────────────────
  const renderer = new THREE.WebGLRenderer({ antialias: !isMobile, alpha: false });
  renderer.setPixelRatio(isMobile ? Math.min(window.devicePixelRatio, 1.5) : Math.min(window.devicePixelRatio, 2));
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  container.appendChild(renderer.domElement);

  // ── Scene + camera + controls ─────────────────────────────────────────
  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0x1a1a1a);

  const camera = new THREE.PerspectiveCamera(
    60, container.clientWidth / container.clientHeight, 0.01, 1e7
  );
  camera.position.set(0, 0, 5);

  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;

  // ── Loading UI ────────────────────────────────────────────────────────
  const progress = document.createElement('div');
  progress.className = 'viewer-progress';
  progress.innerHTML =
    '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
    '<span class="viewer-progress-text">chargement…</span>';
  container.appendChild(progress);
  const fill = progress.querySelector('.viewer-progress-fill');
  const ptext = progress.querySelector('.viewer-progress-text');

  function onProgress(e) {
    if (e && e.lengthComputable) {
      const pct = Math.round((e.loaded / e.total) * 100);
      fill.style.width = pct + '%';
      ptext.textContent = 'chargement… ' + pct + '%';
    }
  }
  function onError(err) {
    ptext.textContent = 'impossible de charger le nuage de points.';
    fill.style.background = '#c0392b';
    if (window.console && window.console.error) window.console.error('point cloud load failed', err);
  }
  function hideProgress() {
    progress.style.opacity = '0';
    setTimeout(() => progress.remove(), 400);
  }

  // ── Point-size controls (− / +) ───────────────────────────────────────
  // Created up-front but only useful once a material exists; clicks no-op
  // until then. 44 px targets on touch, compact on desktop.
  let pointsMaterial = null;
  const sizeCtl = document.createElement('div');
  sizeCtl.className = 'pc-size-controls' + (isMobile ? ' pc-size-controls--touch' : '');
  sizeCtl.innerHTML =
    '<button type="button" class="pc-size-btn" data-dir="down" aria-label="Réduire la taille des points" title="Points plus petits">−</button>' +
    '<span class="pc-size-label">points</span>' +
    '<button type="button" class="pc-size-btn" data-dir="up" aria-label="Augmenter la taille des points" title="Points plus gros">+</button>';
  container.appendChild(sizeCtl);
  sizeCtl.addEventListener('click', (e) => {
    const btn = e.target.closest('.pc-size-btn');
    if (!btn || !pointsMaterial) return;
    const f = btn.dataset.dir === 'up' ? 1.25 : 0.8;
    pointsMaterial.size = THREE.MathUtils.clamp(pointsMaterial.size * f, 1e-5, 1e5);
    pointsMaterial.needsUpdate = true;
  });
  // Keyboard + / − as a desktop nicety.
  if (!isMobile) {
    window.addEventListener('keydown', (e) => {
      if (!pointsMaterial || e.target.matches('input, textarea')) return;
      if (e.key === '+' || e.key === '=') pointsMaterial.size *= 1.25;
      else if (e.key === '-' || e.key === '_') pointsMaterial.size *= 0.8;
      else return;
      pointsMaterial.needsUpdate = true;
    });
  }

  // ── Controls hint (desktop only — same pattern as viewer.js) ──────────
  if (!isMobile) {
    const hint = document.createElement('div');
    hint.className = 'viewer-controls-hint';
    hint.innerHTML =
      '<span class="viewer-controls-hint__label">Rotation</span>' +
      '<svg width="22" height="32" viewBox="0 0 22 32" fill="none" xmlns="http://www.w3.org/2000/svg">' +
      '<rect x="1" y="1" width="20" height="30" rx="10" stroke="rgba(255,255,255,0.35)" stroke-width="1.5"/>' +
      '<path d="M1 12 L1 9 Q1 2 11 2 L11 15 L1 15 Z" fill="rgba(255,255,255,0.15)"/>' +
      '<path d="M21 12 L21 9 Q21 2 11 2 L11 15 L21 15 Z" fill="rgba(255,255,255,0.15)"/>' +
      '<line x1="11" y1="2" x2="11" y2="15" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>' +
      '<rect x="9.5" y="5" width="3" height="6" rx="1.5" fill="rgba(255,255,255,0.5)"/>' +
      '</svg>' +
      '<span class="viewer-controls-hint__label">Déplacer</span>';
    container.appendChild(hint);

    let hintTimer = null;
    let pointerDown = false;
    function scheduleHint() {
      clearTimeout(hintTimer);
      if (!pointerDown) {
        hintTimer = setTimeout(() => hint.classList.add('is-visible'), 1000);
      }
    }
    function onPointerDown() {
      pointerDown = true;
      clearTimeout(hintTimer);
      hint.classList.remove('is-visible');
    }
    function onPointerUp() {
      pointerDown = false;
      scheduleHint();
    }
    scheduleHint();
    renderer.domElement.addEventListener('pointerdown', onPointerDown);
    renderer.domElement.addEventListener('pointerup', onPointerUp);
    renderer.domElement.addEventListener('pointercancel', onPointerUp);
    renderer.domElement.addEventListener('wheel', () => {
      clearTimeout(hintTimer);
      hint.classList.remove('is-visible');
      scheduleHint();
    }, { passive: true });
  }

  // ── Add a cloud, centre it, fix the up-axis, and frame the camera ─────
  function addCloud(geometry, existingPoints) {
    geometry.computeBoundingBox();
    const size = new THREE.Vector3();
    const center = new THREE.Vector3();
    geometry.boundingBox.getSize(size);
    geometry.boundingBox.getCenter(center);
    const diag = size.length() || 1;
    const hasColor = !!geometry.getAttribute('color');

    let points;
    if (existingPoints) {
      // PCDLoader already returns a THREE.Points; just tune its material.
      points = existingPoints;
      points.material.size = diag / 800;
      points.material.sizeAttenuation = true;
      if (hasColor) points.material.vertexColors = true;
      points.material.needsUpdate = true;
    } else {
      const material = new THREE.PointsMaterial({
        size: diag / 800,
        sizeAttenuation: true,
        vertexColors: hasColor,
        color: hasColor ? 0xffffff : 0x9fb4c7,
      });
      points = new THREE.Points(geometry, material);
    }
    pointsMaterial = points.material;

    // Round points instead of GL's default squares.
    makeRound(pointsMaterial);

    // Lift scan colours — they read dim on the dark stage otherwise.
    if (hasColor) pointsMaterial.color.setScalar(COLOR_BOOST);

    // Recentre by baking the offset into the VERTICES, not the object's
    // position. Scan exports carry large absolute coordinates (survey CRS,
    // or a metre-scale local frame offset from origin). If we only moved the
    // object (points.position.sub(center)) the vertex attribute would still
    // hold those large values, and `modelViewMatrix * position` in float32
    // suffers catastrophic cancellation — the cloud visibly vibrates as the
    // camera rotates. Translating the geometry makes the stored coordinates
    // small (centred on 0), which keeps the matrix math precise.
    geometry.translate(-center.x, -center.y, -center.z);
    geometry.computeBoundingSphere();

    const wrap = new THREE.Group();
    wrap.rotation.x = Z_UP_FIX;
    wrap.add(points);
    scene.add(wrap);

    const radius = diag / 2;
    camera.position.set(radius * 1.2, radius * 0.55, radius * 1.4);
    // Keep the near/far span tight around the model so the depth buffer has
    // adequate precision (a huge near:far ratio also causes flicker).
    camera.near = Math.max(radius / 500, 0.01);
    camera.far = radius * 20;
    camera.updateProjectionMatrix();
    controls.target.set(0, 0, 0);
    controls.update();

    hideProgress();
    document.body.classList.add('viewer-is-ready');
  }

  // ── Load ──────────────────────────────────────────────────────────────
  if (format === 'pcd') {
    new PCDLoader().load(src, (pts) => addCloud(pts.geometry, pts), onProgress, onError);
  } else {
    new PLYLoader().load(src, (geo) => addCloud(geo, null), onProgress, onError);
  }

  // ── Resize + render loop ──────────────────────────────────────────────
  function resize() {
    const w = container.clientWidth, h = container.clientHeight;
    if (!w || !h) return;
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    renderer.setSize(w, h);
  }
  window.addEventListener('resize', resize);

  (function animate() {
    requestAnimationFrame(animate);
    controls.update();
    renderer.render(scene, camera);
  })();
}

const el = document.getElementById('gh-pointcloud-viewer');
if (el) initPointCloud(el);
