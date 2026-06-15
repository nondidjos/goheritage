/**
 * GoHéritage — COPC streaming point-cloud viewer
 *
 * Unlike pointcloud-viewer.js (which downloads a whole PLY/PCD and renders it
 * as one flat THREE.Points), this streams a Cloud-Optimized Point Cloud
 * (.copc.laz) straight off static Apache via HTTP range requests. A COPC file
 * carries an octree baked into a single .laz, so we only fetch + decode the
 * nodes the camera can actually see, at a level of detail matched to their
 * on-screen size. That makes hundred-million-point scans navigable in the
 * browser with no tiling server — the conversion is done offline once with
 * PDAL (`pdal translate in.las out.copc.laz`) and the result is just uploaded.
 *
 * Mounts onto #gh-copc-viewer and reads:
 *   data-src   — URL of the .copc.laz file (same-origin)
 *   data-wasm  — URL of laz-perf.wasm (so the emscripten glue can locate it)
 *
 * copc + laz-perf are CommonJS, so this file is BUNDLED by build-js.mjs
 * (three.js stays external and resolves through the page importmap).
 */

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { Copc } from 'copc';
import { createLazPerf } from 'laz-perf';

// Scanner data is Z-up; three.js is Y-up. Same fix the PLY/OBJ paths apply.
const Z_UP_FIX = -Math.PI / 2;

// Scan RGB is sRGB-encoded (8- or 16-bit). The renderer output-encodes to
// sRGB, so vertex colours must be uploaded in LINEAR space or every midtone
// gets lifted and the cloud washes out near-white. Convert on upload.
function srgbToLinear(c) {
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

// LOD tuning. A node is worth loading once its cube projects to at least
// MIN_NODE_PX pixels tall; we keep loading higher-priority (bigger on screen)
// nodes until the budget is spent. Budget is deliberately conservative — it's
// the in-GPU point ceiling, independent of the file's total size.
// A node refines the view once its cube projects to at least MIN_NODE_PX tall.
// Nodes at or above COVERAGE_DEPTH always load when in frustum (regardless of
// projected size) so the cloud reads as COMPLETE — if only big-on-screen nodes
// loaded, a zoomed-out/immobile view would show sparse holes.
const MIN_NODE_PX = 64;
const COVERAGE_DEPTH = 4;
const MAX_POINTS = 4_000_000;
const MAX_CONCURRENT = 6;

// ── Round-point sprite (shared) ───────────────────────────────────────────
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

// Native fetch range getter — same-origin, so the browser tags it
// Sec-Fetch-Site: same-origin and the .htaccess anti-rip gate lets it through.
function makeGetter(url) {
  return async function get(begin, end) {
    if (begin < 0 || end < 0 || begin > end) throw new Error('invalid range');
    const res = await fetch(url, {
      headers: { Range: `bytes=${begin}-${end - 1}` },
      credentials: 'same-origin',
    });
    if (!res.ok) throw new Error('range fetch failed: HTTP ' + res.status);
    return new Uint8Array(await res.arrayBuffer());
  };
}

// Cube of one octree node, derived by subdividing the root cube `d` times.
function nodeCube(root, d, x, y, z) {
  const n = 2 ** d;
  const sx = (root[3] - root[0]) / n;
  const sy = (root[4] - root[1]) / n;
  const sz = (root[5] - root[2]) / n;
  return {
    min: [root[0] + x * sx, root[1] + y * sy, root[2] + z * sz],
    size: [sx, sy, sz],
  };
}

async function initCopc(container) {
  const src = container.dataset.src;
  const wasmUrl = container.dataset.wasm;
  if (!src) return;

  if (getComputedStyle(container).position === 'static') {
    container.style.position = 'relative';
  }

  const isMobile = window.matchMedia('(hover: none) and (pointer: coarse)').matches
                || window.innerWidth <= 768;

  // ── Renderer (adaptive DPR + GPU hint, same policy as pointcloud-viewer) ─
  const renderer = new THREE.WebGLRenderer({
    antialias: !isMobile,
    alpha: false,
    powerPreference: isMobile ? 'low-power' : 'high-performance',
  });
  const FULL_DPR = isMobile ? Math.min(window.devicePixelRatio, 1.5) : Math.min(window.devicePixelRatio, 2);
  const LOW_DPR = Math.min(FULL_DPR, 1);
  renderer.setPixelRatio(FULL_DPR);
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  container.appendChild(renderer.domElement);

  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0x1a1a1a);

  const camera = new THREE.PerspectiveCamera(
    60, container.clientWidth / container.clientHeight, 0.01, 1e7
  );
  camera.position.set(0, 0, 5);

  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  // Higher = settles faster; a low value leaves a long, obvious glide.
  controls.dampingFactor = 0.15;

  // The Z-up→Y-up wrapper that every node is parented to.
  const wrap = new THREE.Group();
  wrap.rotation.x = Z_UP_FIX;
  scene.add(wrap);

  // ── Loading UI ──────────────────────────────────────────────────────────
  const progress = document.createElement('div');
  progress.className = 'viewer-progress';
  progress.innerHTML =
    '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
    '<span class="viewer-progress-text">chargement…</span>';
  container.appendChild(progress);
  const fill = progress.querySelector('.viewer-progress-fill');
  const ptext = progress.querySelector('.viewer-progress-text');
  function setProgress(pct, label) {
    fill.style.width = pct + '%';
    ptext.textContent = label;
  }
  function fail(err) {
    ptext.textContent = 'impossible de charger le nuage de points.';
    fill.style.background = '#c0392b';
    if (window.console) console.error('[copc] load failed', err);
  }
  let progressGone = false;
  function hideProgress() {
    if (progressGone) return;
    progressGone = true;
    progress.style.opacity = '0';
    setTimeout(() => progress.remove(), 400);
    document.body.classList.add('viewer-is-ready');
  }

  // ── Shared material ───────────────────────────────────────────────────────
  let material = null;
  let colorScale = 1 / 255;       // resolved from the first decoded node
  let colorScaleResolved = false;
  let grayscale = false;          // B&W scan → render an elevation grey ramp

  // ── Point-size controls ───────────────────────────────────────────────────
  const sizeCtl = document.createElement('div');
  sizeCtl.className = 'pc-size-controls' + (isMobile ? ' pc-size-controls--touch' : '');
  sizeCtl.innerHTML =
    '<button type="button" class="pc-size-btn" data-dir="down" aria-label="Réduire la taille des points">−</button>' +
    '<span class="pc-size-label">Taille des points</span>' +
    '<button type="button" class="pc-size-btn" data-dir="up" aria-label="Augmenter la taille des points">+</button>';
  container.appendChild(sizeCtl);
  sizeCtl.addEventListener('click', (e) => {
    const btn = e.target.closest('.pc-size-btn');
    if (!btn || !material) return;
    const f = btn.dataset.dir === 'up' ? 1.25 : 0.8;
    material.size = THREE.MathUtils.clamp(material.size * f, 1e-6, 1e6);
    material.needsUpdate = true;
    requestRender();
  });

  // ── On-demand render + adaptive DPR (see pointcloud-viewer.js) ────────────
  let renderRequested = false;
  let interacting = false;
  function requestRender() {
    if (renderRequested) return;
    renderRequested = true;
    requestAnimationFrame(frame);
  }
  function frame() {
    renderRequested = false;
    const moving = controls.update();
    // Low DPR only while the pointer is actively down — coast + rest stay full
    // DPR, so the glide doesn't sharpen-pop when it stops.
    const want = interacting ? LOW_DPR : FULL_DPR;
    if (renderer.getPixelRatio() !== want) {
      renderer.setPixelRatio(want);
      renderer.setSize(container.clientWidth, container.clientHeight);
    }
    renderer.render(scene, camera);
    if (interacting || moving) requestRender();
  }
  controls.addEventListener('change', requestRender);
  controls.addEventListener('start', () => { interacting = true; requestRender(); });
  controls.addEventListener('end', () => { interacting = false; scheduleLod(); requestRender(); });

  // ── COPC state ────────────────────────────────────────────────────────────
  const get = makeGetter(src);
  let copc = null;
  let lazPerf = null;
  let rootCube = null;
  let hasColor = false;
  const offset = [0, 0, 0];        // global recenter (cube centre) to keep float32 precise
  let zMin = 0, zSpan = 1;         // vertical extent → height ramp for colourless clouds

  const known = new Map();         // key "d-x-y-z" -> { pointCount, pointDataOffset, pointDataLength }
  const pageRefs = new Map();      // key -> { pageOffset, pageLength } (lazy sub-hierarchy)
  const pagesLoaded = new Set();
  const pagesLoading = new Set();
  const loaded = new Map();        // key -> THREE.Points
  const loading = new Set();
  let inFlight = 0;
  const queue = [];                // [{key, node, score}]

  const frustum = new THREE.Frustum();
  const projScreen = new THREE.Matrix4();
  const _v = new THREE.Vector3();
  const _sphere = new THREE.Sphere();

  function ingest(h) {
    for (const k in h.nodes) known.set(k, h.nodes[k]);
    for (const k in h.pages) pageRefs.set(k, h.pages[k]);
  }

  async function loadPage(key, ref) {
    if (pagesLoaded.has(key) || pagesLoading.has(key)) return;
    pagesLoading.add(key);
    try {
      const h = await Copc.loadHierarchyPage(get, ref);
      ingest(h);
      pagesLoaded.add(key);
      scheduleLod();
    } catch (e) {
      if (window.console) console.warn('[copc] hierarchy page failed', key, e);
    } finally {
      pagesLoading.delete(key);
    }
  }

  // World-space bounding sphere of a node, accounting for the recenter offset
  // and the Z-up→Y-up wrap, so we can frustum-test + measure screen size.
  function nodeSphere(d, x, y, z) {
    const c = nodeCube(rootCube, d, x, y, z);
    _v.set(
      c.min[0] + c.size[0] / 2 - offset[0],
      c.min[1] + c.size[1] / 2 - offset[1],
      c.min[2] + c.size[2] / 2 - offset[2]
    );
    wrap.localToWorld(_v);
    const radius = Math.max(c.size[0], c.size[1], c.size[2]) * 0.5 * Math.sqrt(3);
    return { center: _v.clone(), radius, worldSize: Math.max(c.size[0], c.size[1], c.size[2]) };
  }

  function scheduleLod() {
    if (scheduleLod._t) return;
    scheduleLod._t = setTimeout(() => { scheduleLod._t = null; updateLod(); }, 120);
  }

  function updateLod() {
    if (!rootCube) return;
    camera.updateMatrixWorld();
    projScreen.multiplyMatrices(camera.projectionMatrix, camera.matrixWorldInverse);
    frustum.setFromProjectionMatrix(projScreen);
    const fovFactor = container.clientHeight / (2 * Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)));

    const wanted = [];
    for (const [key, node] of known) {
      const [d, x, y, z] = key.split('-').map(Number);
      const s = nodeSphere(d, x, y, z);
      _sphere.set(s.center, s.radius);
      if (!frustum.intersectsSphere(_sphere)) continue;
      const dist = Math.max(camera.position.distanceTo(s.center), 1e-3);
      const screen = (s.worldSize / dist) * fovFactor;
      const cover = d <= COVERAGE_DEPTH;        // baseline coverage tier
      if (!cover && screen < MIN_NODE_PX) continue;
      wanted.push({ key, node, screen, cover });
    }
    // Coverage tier first (so the coarse cloud is always complete), then refine
    // by on-screen size within the point budget.
    wanted.sort((a, b) => (b.cover - a.cover) || (b.screen - a.screen));

    // Spend the point budget on the biggest-on-screen nodes first.
    const keep = new Set();
    let budget = MAX_POINTS;
    for (const w of wanted) {
      if (budget - w.node.pointCount < 0) continue;
      budget -= w.node.pointCount;
      keep.add(w.key);
      // Reveal deeper detail under nodes that are large on screen.
      const ref = pageRefs.get(w.key);
      if (ref && w.screen > MIN_NODE_PX * 1.5) loadPage(w.key, ref);
    }

    // Drop nodes we no longer want.
    for (const key of [...loaded.keys()]) {
      if (!keep.has(key)) unloadNode(key);
    }
    // Enqueue the ones we want but don't have, nearest (biggest) first.
    queue.length = 0;
    for (const w of wanted) {
      if (keep.has(w.key) && !loaded.has(w.key) && !loading.has(w.key)) {
        queue.push(w);
      }
    }
    pump();
  }

  function pump() {
    while (inFlight < MAX_CONCURRENT && queue.length) {
      const { key, node } = queue.shift();
      loadNode(key, node);
    }
  }

  async function loadNode(key, node) {
    if (loaded.has(key) || loading.has(key)) return;
    loading.add(key);
    inFlight++;
    try {
      const view = await Copc.loadPointDataView(get, copc, node, { lazPerf });
      const n = view.pointCount;
      const gx = view.getter('X'), gy = view.getter('Y'), gz = view.getter('Z');
      let gr, gg, gb;
      if (hasColor) { gr = view.getter('Red'); gg = view.getter('Green'); gb = view.getter('Blue'); }

      if (hasColor && !colorScaleResolved) {
        // Decide 8- vs 16-bit AND detect a greyscale (B&W) scan from the WHOLE
        // strided root node (the first 2048 points can all be dark and mis-trip
        // the bit-depth choice, clamping a 16-bit file to white). The root node
        // is decoded first (see boot) so this is resolved before other nodes
        // build — no RGB/ramp mix from a concurrent decode.
        let max = 0, spread = 0;
        const step = Math.max(1, Math.floor(n / 8192));
        for (let i = 0; i < n; i += step) {
          const r = gr(i), g = gg(i), b = gb(i);
          if (r > max) max = r; if (g > max) max = g; if (b > max) max = b;
          const d = Math.max(r, g, b) - Math.min(r, g, b);
          if (d > spread) spread = d;
        }
        colorScale = max > 255 ? 1 / 65535 : 1 / 255;
        // Channels ~equal everywhere → no real colour (B&W scan): render a
        // height ramp instead of the muddy grey the data carries.
        if (spread * colorScale < 0.02) grayscale = true;
        colorScaleResolved = true;
      }

      // Colourless cloud (no RGB at all, or detected B&W) → low-to-high grey
      // ramp by elevation, which restores the form a flat colour would lose.
      const ramp = !hasColor || grayscale;
      const pos = new Float32Array(n * 3);
      const col = new Float32Array(n * 3);
      for (let i = 0; i < n; i++) {
        const Z = gz(i);
        pos[i * 3]     = gx(i) - offset[0];
        pos[i * 3 + 1] = gy(i) - offset[1];
        pos[i * 3 + 2] = Z - offset[2];
        if (ramp) {
          let t = (Z - zMin) / zSpan;
          t = t < 0 ? 0 : t > 1 ? 1 : t;
          const g = srgbToLinear(0.4 + 0.6 * t);   // floor 0.4 so low points stay visible on the dark stage
          col[i * 3] = g; col[i * 3 + 1] = g; col[i * 3 + 2] = g;
        } else {
          col[i * 3]     = srgbToLinear(gr(i) * colorScale);
          col[i * 3 + 1] = srgbToLinear(gg(i) * colorScale);
          col[i * 3 + 2] = srgbToLinear(gb(i) * colorScale);
        }
      }

      const geo = new THREE.BufferGeometry();
      geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
      if (col) geo.setAttribute('color', new THREE.BufferAttribute(col, 3));
      geo.computeBoundingSphere();

      const points = new THREE.Points(geo, material);
      wrap.add(points);
      loaded.set(key, points);
      hideProgress();
      requestRender();
    } catch (e) {
      if (window.console) console.warn('[copc] node failed', key, e);
    } finally {
      loading.delete(key);
      inFlight--;
      pump();
    }
  }

  function unloadNode(key) {
    const pts = loaded.get(key);
    if (!pts) return;
    wrap.remove(pts);
    pts.geometry.dispose();
    loaded.delete(key);
  }

  // ── Boot ──────────────────────────────────────────────────────────────────
  try {
    setProgress(8, 'lecture de l’en-tête…');
    lazPerf = await createLazPerf({ locateFile: () => wasmUrl });
    copc = await Copc.create(get);

    // info.cube drives the octree node math (keys subdivide the cube), so keep
    // it for LOD. But the cube is padded to the largest axis — framing on it
    // sits the camera way too far back. Recenter + frame on the TIGHT data
    // bounds from the LAS header instead.
    rootCube = copc.info.cube;
    const hmin = copc.header.min, hmax = copc.header.max;
    offset[0] = (hmin[0] + hmax[0]) / 2;
    offset[1] = (hmin[1] + hmax[1]) / 2;
    offset[2] = (hmin[2] + hmax[2]) / 2;
    const pdrf = copc.header.pointDataRecordFormat;
    hasColor = pdrf === 2 || pdrf === 3 || pdrf === 7 || pdrf === 8;

    // Material — small world-space size with distance attenuation. We ALWAYS
    // colour per-point (RGB, or a height ramp for colourless/B&W clouds), so
    // vertexColors stays on. The base size is a fraction of the root spacing;
    // a shader clamp (below) caps the on-screen size so near points can't
    // balloon and wreck mobile fill-rate.
    zMin = copc.header.min[2];
    zSpan = (copc.header.max[2] - copc.header.min[2]) || 1;
    material = new THREE.PointsMaterial({
      size: (copc.info.spacing * 0.2) || 0.02,
      sizeAttenuation: true,
      vertexColors: true,
      map: circleTexture(),
      alphaTest: 0.5,
      transparent: false,
    });
    // Clamp gl_PointSize to a sane pixel range: distance attenuation stays, but
    // points never exceed ~a few px (mobile fill-rate guard) nor drop below 1px
    // (so far points don't vanish). Range is in framebuffer px; FULL_DPR scales
    // CSS px → device px. Cheap: one clamp in the vertex shader.
    const maxPx = (isMobile ? 3.0 : 5.0) * FULL_DPR;
    material.onBeforeCompile = (shader) => {
      shader.uniforms.uMinPx = { value: 1.0 };
      shader.uniforms.uMaxPx = { value: maxPx };
      shader.vertexShader =
        'uniform float uMinPx;\nuniform float uMaxPx;\n' +
        shader.vertexShader.replace(
          '#include <fog_vertex>',
          'gl_PointSize = clamp( gl_PointSize, uMinPx, uMaxPx );\n#include <fog_vertex>'
        );
    };

    // Frame on the tight data extent. dist ~ radius/sin(fov/2) fits the
    // bounding sphere to the viewport. Use a FIXED look-down pitch (not an
    // elevation proportional to radius) — a radius-fraction puts the camera
    // way overhead on wide/flat site scans (e.g. abbaye), where the cloud is a
    // thin horizontal slab. A gentle fixed pitch reads as a 3/4 view for both
    // tall buildings and flat sites.
    const sx = hmax[0] - hmin[0], sy = hmax[1] - hmin[1], sz = hmax[2] - hmin[2];
    const radius = Math.hypot(sx, sy, sz) / 2 || 1;
    const dist = (radius / Math.sin(THREE.MathUtils.degToRad(camera.fov / 2))) * 1.05;
    const pitch = THREE.MathUtils.degToRad(16);   // gentle look-down
    // Geometry is Z-up: horizontal plane is X/Y, up is +Z.
    const eye = new THREE.Vector3(0.7, -0.7, 0).normalize()
      .multiplyScalar(Math.cos(pitch))
      .addScaledVector(new THREE.Vector3(0, 0, 1), Math.sin(pitch))
      .multiplyScalar(dist);
    wrap.updateMatrixWorld(true);
    camera.position.copy(wrap.localToWorld(eye.clone()));
    camera.near = Math.max(radius / 500, 0.01);
    camera.far = (dist + radius) * 4;
    camera.updateProjectionMatrix();
    controls.target.set(0, 0, 0);
    controls.update();
    scene.fog = new THREE.Fog(0x1a1a1a, dist, dist + radius * 4);

    setProgress(20, 'chargement de l’octree…');
    const rootPage = await Copc.loadHierarchyPage(get, copc.info.rootHierarchyPage);
    ingest(rootPage);
    // Decode the root node FIRST and await it: that resolves the colour mode
    // (bit depth + B&W) so every subsequent node colours consistently, and it
    // guarantees the stage is never empty. Then refine by view.
    const rootKey = known.keys().next().value;
    if (rootKey) await loadNode(rootKey, known.get(rootKey));
    updateLod();
  } catch (e) {
    fail(e);
    return;
  }

  // ── Resize + teardown ───────────────────────────────────────────────────
  function resize() {
    const w = container.clientWidth, h = container.clientHeight;
    if (!w || !h) return;
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    renderer.setSize(w, h);
    scheduleLod();
    requestRender();
  }
  window.addEventListener('resize', resize);

  function dispose() {
    window.removeEventListener('resize', resize);
    controls.removeEventListener('change', requestRender);
    controls.dispose();
    for (const key of [...loaded.keys()]) unloadNode(key);
    if (material) material.dispose();
    if (_circleTex) { _circleTex.dispose(); _circleTex = null; }
    renderer.dispose();
    renderer.forceContextLoss();
  }
  window.addEventListener('pagehide', dispose, { once: true });
}

const el = document.getElementById('gh-copc-viewer');
if (el) initCopc(el);
