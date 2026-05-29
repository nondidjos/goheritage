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

function initPointCloud(container) {
  const src = container.dataset.src;
  const format = (container.dataset.format || 'ply').toLowerCase();
  if (!src) return;

  const isMobile = window.innerWidth <= 768 || ('ontouchstart' in window);

  // ── Renderer ──────────────────────────────────────────────────────────
  const renderer = new THREE.WebGLRenderer({ antialias: !isMobile, alpha: false });
  renderer.setPixelRatio(isMobile ? 1 : Math.min(window.devicePixelRatio, 2));
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
  progress.className = 'pc-progress';
  progress.innerHTML =
    '<div class="pc-progress__bar"><div class="pc-progress__fill"></div></div>' +
    '<div class="pc-progress__text">Chargement…</div>';
  container.appendChild(progress);
  const fill = progress.querySelector('.pc-progress__fill');
  const ptext = progress.querySelector('.pc-progress__text');

  function onProgress(e) {
    if (e && e.lengthComputable) {
      const pct = Math.round((e.loaded / e.total) * 100);
      fill.style.width = pct + '%';
      ptext.textContent = 'Chargement… ' + pct + '%';
    }
  }
  function onError(err) {
    ptext.textContent = 'Impossible de charger le nuage de points.';
    fill.style.background = '#c0392b';
    if (window.console && window.console.error) window.console.error('point cloud load failed', err);
  }

  // ── Add a cloud, centre it, and frame the camera ──────────────────────
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

    points.position.sub(center); // recentre on the origin
    scene.add(points);

    const radius = diag / 2;
    camera.position.set(radius * 1.4, radius * 0.8, radius * 1.6);
    camera.near = Math.max(radius / 1000, 0.001);
    camera.far = radius * 100;
    camera.updateProjectionMatrix();
    controls.target.set(0, 0, 0);
    controls.update();

    progress.remove();
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
