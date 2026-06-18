/**
 * GoHéritage — shared point-cloud viewer scaffolding
 *
 * The two point-cloud viewers — pointcloud-viewer.js (whole-file PLY/PCD) and
 * copc-viewer.js (streamed COPC octree) — share everything except how they
 * source and colour their points: the renderer + adaptive-DPR setup, the
 * on-demand render loop, the round-point sprite, the loading/size/hint chrome,
 * and teardown. That common scaffolding lives here so the two can't drift.
 *
 * three.js is imported with bare specifiers; both viewers are bundled by
 * build-js.mjs with `three` marked external, so this module is inlined into
 * each viewer's .min.js while three still resolves via the page importmap.
 */

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

// Scanner exports (CloudCompare e57/PLY, survey data) are Z-up; three.js is
// Y-up. Same -90° X rotation the OBJ/GLB pipeline applies in viewer.js.
export const Z_UP_FIX = -Math.PI / 2;

// ── Round-point sprite ─────────────────────────────────────────────────────
// A soft white disc on transparent. With alphaTest it turns the default square
// GL_POINT into a clean circle with no transparency-sort artefacts (cut pixels
// are discarded, depth stays correct). Built once, shared across materials.
let _circleTex = null;
export function circleTexture() {
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
export function disposeCircleTexture() {
  if (_circleTex) { _circleTex.dispose(); _circleTex = null; }
}

// Apply the circle sprite + alpha cutout to a PointsMaterial in place.
export function makeRound(material) {
  material.map = circleTexture();
  material.alphaTest = 0.5;   // discard the square corners
  material.transparent = false;
  material.needsUpdate = true;
}

// Touch-primary devices only (phones/tablets) — not touch laptops.
export function isMobileViewport() {
  return window.matchMedia('(hover: none) and (pointer: coarse)').matches
      || window.innerWidth <= 768;
}

// ── Stage: renderer + scene + camera + controls ─────────────────────────────
// Returns a `stage` object the other helpers below operate on. FULL_DPR is the
// crisp resting resolution; LOW_DPR is used while the pointer is down (dense
// clouds are fragment-bound, so dropping DPR during motion keeps drags smooth).
export function createStage(container) {
  // Overlays are absolutely positioned; if the host is static they'd centre
  // against some ancestor (the "off-centre loading bar" bug). Force context.
  if (getComputedStyle(container).position === 'static') {
    container.style.position = 'relative';
  }

  const isMobile = isMobileViewport();

  const renderer = new THREE.WebGLRenderer({
    antialias: !isMobile,
    alpha: false,
    powerPreference: isMobile ? 'low-power' : 'high-performance',
  });
  const FULL_DPR = isMobile ? Math.min(window.devicePixelRatio, 1.5) : Math.min(window.devicePixelRatio, 2);
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

  return { container, isMobile, renderer, FULL_DPR, scene, camera, controls };
}

// ── On-demand render loop ────────────────────────────────────────────────────
// A static cloud doesn't change between frames, so there's no permanent rAF
// loop (continuous full-res GPU work + battery drain). We render only when
// something moves: interaction fires OrbitControls' 'change', and while damping
// settles controls.update() keeps returning true so we self-reschedule until
// the camera rests, then go idle. Pixel ratio is held CONSTANT (FULL_DPR) —
// dropping it during motion made the cloud visibly change brightness/sharpness
// between moving and resting (the fixed point-size clamp covers relatively more
// screen at a lower DPR). `onEnd` lets a viewer hook the gesture-end (e.g. a
// COPC LOD refresh). Returns { requestRender }.
export function createRenderLoop(stage, { onEnd } = {}) {
  const { renderer, scene, camera, controls } = stage;
  let renderRequested = false;

  function requestRender() {
    if (renderRequested) return;
    renderRequested = true;
    requestAnimationFrame(frame);
  }
  function frame() {
    renderRequested = false;
    const moving = controls.update();
    renderer.render(scene, camera);
    if (moving) requestRender();                   // keep rendering until damping rests
  }

  controls.addEventListener('change', requestRender);
  controls.addEventListener('end', () => { if (onEnd) onEnd(); requestRender(); });

  stage._requestRender = requestRender;            // stashed so disposeStage can unbind it
  return { requestRender };
}

// Window resize → keep the renderer/camera matched to the container. `extra`
// runs before the render (e.g. a COPC LOD refresh). Returns the handler so
// disposeStage can unbind it.
export function attachResize(stage, requestRender, extra) {
  const { container, camera, renderer } = stage;
  function resize() {
    const w = container.clientWidth, h = container.clientHeight;
    if (!w || !h) return;
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    renderer.setSize(w, h);
    if (extra) extra();
    requestRender();
  }
  window.addEventListener('resize', resize);
  stage._resize = resize;
  return resize;
}

// ── Loading UI ───────────────────────────────────────────────────────────────
// Returns { onProgress(xhrEvent), setProgress(pct,label), fail(err), hide() }.
export function makeProgress(container) {
  const progress = document.createElement('div');
  progress.className = 'viewer-progress';
  progress.innerHTML =
    '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
    '<span class="viewer-progress-text">chargement…</span>';
  container.appendChild(progress);
  const fill  = progress.querySelector('.viewer-progress-fill');
  const ptext = progress.querySelector('.viewer-progress-text');
  let gone = false;

  return {
    onProgress(e) {
      if (e && e.lengthComputable) {
        const pct = Math.round((e.loaded / e.total) * 100);
        fill.style.width = pct + '%';
        ptext.textContent = 'chargement… ' + pct + '%';
      }
    },
    setProgress(pct, label) {
      fill.style.width = pct + '%';
      ptext.textContent = label;
    },
    fail(err) {
      ptext.textContent = 'impossible de charger le nuage de points.';
      fill.style.background = '#c0392b';
      if (window.console) console.error('point cloud load failed', err);
    },
    hide() {
      if (gone) return;
      gone = true;
      progress.style.opacity = '0';
      setTimeout(() => progress.remove(), 400);
      document.body.classList.add('viewer-is-ready');
    },
  };
}

// ── Point-size controls (− / +) ──────────────────────────────────────────────
// `getMaterial` returns the live PointsMaterial (null until a cloud exists, so
// clicks no-op until then). Returns { onKeydown } for teardown.
export function makeSizeControls(stage, getMaterial, requestRender) {
  const { container, isMobile } = stage;
  const sizeCtl = document.createElement('div');
  sizeCtl.className = 'pc-size-controls' + (isMobile ? ' pc-size-controls--touch' : '');
  sizeCtl.innerHTML =
    '<button type="button" class="pc-size-btn" data-dir="down" aria-label="Réduire la taille des points" title="Points plus petits">−</button>' +
    '<span class="pc-size-label">Taille des points</span>' +
    '<button type="button" class="pc-size-btn" data-dir="up" aria-label="Augmenter la taille des points" title="Points plus gros">+</button>';
  container.appendChild(sizeCtl);
  sizeCtl.addEventListener('click', (e) => {
    const btn = e.target.closest('.pc-size-btn');
    const m = getMaterial();
    if (!btn || !m) return;
    const f = btn.dataset.dir === 'up' ? 1.25 : 0.8;
    m.size = THREE.MathUtils.clamp(m.size * f, 1e-6, 1e6);
    m.needsUpdate = true;
    requestRender();
  });

  let onKeydown = null;
  if (!isMobile) {
    onKeydown = (e) => {
      const m = getMaterial();
      if (!m || e.target.matches('input, textarea')) return;
      if (e.key === '+' || e.key === '=') m.size *= 1.25;
      else if (e.key === '-' || e.key === '_') m.size *= 0.8;
      else return;
      m.needsUpdate = true;
      requestRender();
    };
    window.addEventListener('keydown', onKeydown);
  }
  stage._onKeydown = onKeydown;
  return { onKeydown };
}

// ── Controls hint (desktop only) ─────────────────────────────────────────────
// A drift-in "rotation / pan" cue that hides during interaction. No-op on touch.
export function makeControlsHint(stage) {
  const { container, renderer, isMobile } = stage;
  if (isMobile) return;
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
    if (!pointerDown) hintTimer = setTimeout(() => hint.classList.add('is-visible'), 1000);
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

// ── Teardown ─────────────────────────────────────────────────────────────────
// Release the WebGL context + GPU buffers and unbind global listeners when the
// page/iframe goes away (browsers cap live WebGL contexts ~16). `disposeScene`
// lets a viewer release its own resources (e.g. COPC's streamed node geometry);
// the default disposes every geometry/material in the scene graph.
export function disposeStage(stage, { disposeScene } = {}) {
  const { renderer, controls, scene } = stage;
  if (stage._resize)        window.removeEventListener('resize', stage._resize);
  if (stage._onKeydown)     window.removeEventListener('keydown', stage._onKeydown);
  if (stage._requestRender) controls.removeEventListener('change', stage._requestRender);
  controls.dispose();
  if (disposeScene) {
    disposeScene();
  } else {
    scene.traverse((obj) => {
      if (obj.geometry) obj.geometry.dispose();
      if (obj.material) obj.material.dispose();
    });
  }
  disposeCircleTexture();
  renderer.dispose();
  renderer.forceContextLoss();
}
