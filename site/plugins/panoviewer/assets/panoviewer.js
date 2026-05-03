import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { OBJLoader }     from 'three/addons/loaders/OBJLoader.js';
import { GLTFLoader }    from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader }   from 'three/addons/loaders/DRACOLoader.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';
import * as BufferGeometryUtils from 'three/addons/utils/BufferGeometryUtils.js';

import { ICONS }                    from './panoviewer/icons.js';
import { LRUCache }                 from './panoviewer/lru.js';
import { buildCubeMesh }            from './panoviewer/cube-mesh.js';
import {
  SKYBOX_FACE_ORDER,
  detectPanoramaGroups as _detectPanoramaGroups,
} from './panoviewer/groups.js';
import { measureMixin }             from './panoviewer/measure.js';
import { dollhouseMixin }           from './panoviewer/dollhouse.js';

// Re-export for backward compat with existing consumers (header.php, etc.)
export { SKYBOX_FACE_ORDER };
export const detectPanoramaGroups = _detectPanoramaGroups;

// Shared, lazily-initialized Draco decoder (jsdelivr-hosted by default; override with PanoViewer.dracoPath)
const DRACO_DEFAULT_PATH = 'https://cdn.jsdelivr.net/npm/three@0.158.0/examples/jsm/libs/draco/';
let _sharedDraco = null;
function getDracoLoader(path) {
  if (_sharedDraco) return _sharedDraco;
  _sharedDraco = new DRACOLoader();
  _sharedDraco.setDecoderPath(path || DRACO_DEFAULT_PATH);
  _sharedDraco.preload();
  return _sharedDraco;
}

// ── PanoViewer ─────────────────────────────────────────────────────────────────
export class PanoViewer {
  constructor(container, config = {}) {
    this.container = typeof container === 'string'
      ? document.querySelector(container)
      : container;

    this.cfg = {
      autoRotate:      false,
      autoRotateSpeed: 0.025,   // deg/frame
      autoRotateDelay: 3500,    // ms idle before auto-rotate
      minFov: 30, maxFov: 120, defaultFov: 100,
      damping:          0.87,
      sensitivity:      0.26,
      touchSensitivity: 0.38,
      maxPitch:         85,     // degrees
      textureCacheSize: 8,
      preloadNeighbors: true,   // preload hotspot target scenes
      urlHashSync:      false,  // sync view state to URL hash
      debug:            false,  // verbose console logging
      ...config,
    };

    this._textureCache = new LRUCache(this.cfg.textureCacheSize);
    // Mobile detection — coarse pointer + small viewport. Mobile GPUs have
    // ≤ 1 GB VRAM and can't tolerate 4K cube uploads per scene; we keep
    // them on LOW LOD permanently and shrink the cache.
    this._isMobile = this.cfg.forceMobile === true || (
      typeof window !== 'undefined' && (
        (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) ||
        /iPhone|iPad|iPod|Android/i.test(navigator.userAgent || '')
      )
    );
    // Two LODs per scene (low + high). 16 ≈ 8 scenes; halve on mobile.
    const cubeCacheDefault = this._isMobile ? 8 : 16;
    this._cubeCache = new LRUCache(this.cfg.cubeCacheSize ?? cubeCacheDefault);
    this._cubeCache.onEvict = (group) => {
      // Never dispose the currently-rendered cube. Re-insert at the most
      // recent position (a no-op for the cache, but the active scene's id
      // gets bumped on every loadScene anyway).
      if (group === this._cubeGroup) return;
      this._disposeObject(group);
    };
    // Generation counter so _preloadNeighbors can cancel an old run when
    // the active scene changes mid-queue.
    this._preloadRunId = 0;
    this._cubeBuilds = new Map(); // id → in-flight build Promise (de-dupe)

    // Camera state
    this.yaw   = 0;
    this.pitch = 0;
    this.fov   = this.cfg.defaultFov;

    // Drag state
    this.isDragging  = false;
    this.lastPtr     = { x: 0, y: 0 };
    this.vel         = { yaw: 0, pitch: 0 };

    // Auto-rotate
    this.autoRotating  = false;
    this._autoRotTimer = null;

    // Touch
    this._touches     = [];
    this._pinchBase   = 0;

    // Scenes / hotspots
    this._scenes      = {};
    this._activeId    = null;
    this._hotspots    = [];       // { el, vec3 }
    this._transitioning = false;
    this._lastBlobUrl = null;
    this._lastDropSceneId = null;

    // Tracked listeners for destroy()
    this._listeners = []; // { target, type, fn, opts }

    // Mode: 'pano' | 'dollhouse'
    this._mode = 'pano';

    // 3D model (dollhouse)
    this._model        = null;
    this._modelMeshes  = [];
    this._markersGroup = null;
    this._dollhouseCam = null;
    this._dollhouseCtrls = null;

    // Measure
    this._raycaster = new THREE.Raycaster();
    this._measure   = { active: false, points: [], line: null, labelEls: [] };

    this._init();

    if (this.cfg.scenes?.length) {
      this.cfg.scenes.forEach(s => this.addScene(s));
      const applied = this.cfg.urlHashSync && this.applyHash();
      if (!applied) this.loadScene(this.cfg.scenes[0].id);
    } else {
      this._showEmpty(true);
    }

    this._setupDrop();

    // Periodic hash write (debounced) — writes when view settles
    if (this.cfg.urlHashSync) {
      this._hashTimer = setInterval(() => {
        if (!this.isDragging && Math.abs(this.vel.yaw) < 0.01 && Math.abs(this.vel.pitch) < 0.01) {
          this._writeHash();
        }
      }, 1000);
    }
  }

  // ── Bootstrap ──────────────────────────────────────────────────────────────

  _init() {
    this._buildRenderer();
    this._buildThree();
    this._buildUI();
    this._bindEvents();
    this._loop();
    this._resetAutoTimer();
  }

  _buildRenderer() {
    this.renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.renderer.setSize(this.container.clientWidth, this.container.clientHeight);
    this.renderer.domElement.className = 'pano-canvas';
    this.container.appendChild(this.renderer.domElement);
  }

  _buildThree() {
    this.scene  = new THREE.Scene();
    this.scene.background = new THREE.Color(0x090910);

    const { clientWidth: w, clientHeight: h } = this.container;
    this.camera = new THREE.PerspectiveCamera(this.fov, w / h, 0.1, 1000);
    this.camera.position.set(0, 0, 0.01);

    const geo = new THREE.SphereGeometry(500, 72, 36);
    geo.scale(-1, 1, 1);
    this.sphereMat = new THREE.MeshBasicMaterial({ transparent: true, opacity: 0 });
    this.sphere    = new THREE.Mesh(geo, this.sphereMat);
    this.scene.add(this.sphere);
  }

  _buildUI() {
    const mk = (tag, cls) => { const el = document.createElement(tag); el.className = cls; return el; };

    // Hotspot layer
    this.hsLayer = mk('div', 'pano-hotspot-layer');
    this.container.appendChild(this.hsLayer);

    // Title
    this.titleEl = mk('div', 'pano-title');
    this.titleEl.hidden = true;
    this.container.appendChild(this.titleEl);

    // Compass
    this.compassEl = mk('div', 'pano-compass');
    this.compassEl.innerHTML = `
      <div class="pano-compass-needle">
        <svg viewBox="0 0 24 24" stroke="rgba(255,255,255,0.6)" stroke-width="0.4" stroke-linejoin="round">
          <path d="M12 4 14.5 12 9.5 12 Z" fill="#ff3b30"/>
          <path d="M12 20 14.5 12 9.5 12 Z" fill="#f5f5f5"/>
          <circle cx="12" cy="12" r="1" fill="#1a1a1a" stroke="none"/>
        </svg>
      </div>`;
    this.compassNeedle = this.compassEl.querySelector('.pano-compass-needle');
    this.container.appendChild(this.compassEl);

    // Fullscreen button — top-right, separate from control stack
    this.fullscreenBtn = mk('button', 'pano-btn pano-fullscreen-btn');
    this.fullscreenBtn.dataset.act = 'fullscreen';
    this.fullscreenBtn.title = 'Fullscreen';
    this.fullscreenBtn.innerHTML = ICONS.fullscreen;
    this.fullscreenBtn.addEventListener('click', () => this._toggleFullscreen());
    this.container.appendChild(this.fullscreenBtn);

    // Controls — right side, zoom + measure
    this.ctrlEl = mk('div', 'pano-controls');

    const pair = mk('div', 'pano-btn-pair');
    const zin = mk('button', 'pano-btn');
    zin.dataset.act = 'zoomin'; zin.title = 'Zoom in'; zin.innerHTML = ICONS.plus;
    const zout = mk('button', 'pano-btn');
    zout.dataset.act = 'zoomout'; zout.title = 'Zoom out'; zout.innerHTML = ICONS.minus;
    pair.appendChild(zin); pair.appendChild(zout);
    this.ctrlEl.appendChild(pair);

    this.measureBtn = mk('button', 'pano-btn');
    this.measureBtn.dataset.act = 'measure';
    this.measureBtn.title = 'Measure';
    this.measureBtn.innerHTML = ICONS.measure;
    this.measureBtn.hidden = true; // needs a model to raycast against
    this.ctrlEl.appendChild(this.measureBtn);

    this.container.appendChild(this.ctrlEl);

    // Dollhouse button — separate, lower-left corner
    this.dollhouseEl = mk('div', 'pano-dollhouse');
    this.dollhouseBtn = mk('button', 'pano-btn');
    this.dollhouseBtn.dataset.act = 'dollhouse';
    this.dollhouseBtn.title = 'Dollhouse view';
    this.dollhouseBtn.innerHTML = ICONS.dollhouse;
    this.dollhouseEl.appendChild(this.dollhouseBtn);
    this.dollhouseEl.hidden = true; // revealed once a model is loaded
    this.container.appendChild(this.dollhouseEl);
    this.dollhouseBtn.addEventListener('click', () => {
      this.setMode(this._mode === 'dollhouse' ? 'pano' : 'dollhouse');
    });

    this.ctrlEl.addEventListener('click', e => {
      const btn = e.target.closest('[data-act]');
      if (!btn) return;
      const act = btn.dataset.act;
      if (act === 'zoomin')      this._zoom(-12);
      if (act === 'zoomout')     this._zoom(12);
      if (act === 'fullscreen')  this._toggleFullscreen();
      if (act === 'measure')     this.toggleMeasure();
    });

    // Scene strip
    this.scenesEl = mk('div', 'pano-scenes');
    this.scenesEl.hidden = true;
    this.container.appendChild(this.scenesEl);

    // Loading
    this.loadingEl = mk('div', 'pano-loading');
    this.loadingEl.classList.add('hidden');
    this.loadingEl.innerHTML = `
      <div class="pano-spinner"></div>
      <div class="pano-loading-text">Loading</div>
      <div class="pano-loading-track"><div class="pano-loading-bar"></div></div>`;
    this.loadingBar = this.loadingEl.querySelector('.pano-loading-bar');
    this.container.appendChild(this.loadingEl);

    // Black fade overlay
    this.fadeEl = mk('div', 'pano-fade');
    this.container.appendChild(this.fadeEl);

    // Empty state
    this.emptyEl = mk('div', 'pano-empty');
    this.emptyEl.innerHTML = `
      <div class="pano-empty-glyph">⬡</div>
      <div class="pano-empty-title">No panorama loaded</div>
      <div class="pano-empty-sub">viewer.loadImage(url) or drop an image</div>`;
    this.container.appendChild(this.emptyEl);

    // Drop overlay
    this.dropEl = mk('div', 'pano-drop');
    this.dropEl.innerHTML = `
      <div class="pano-drop-ring"><div class="pano-drop-icon">↓</div></div>
      <div class="pano-drop-label">Drop panorama(s) — equirect or Matterport skybox sets</div>`;
    this.container.appendChild(this.dropEl);

    // Zoom toast
    this.zoomToast = mk('div', 'pano-zoom-toast');
    this.container.appendChild(this.zoomToast);
  }

  // ── Events ─────────────────────────────────────────────────────────────────

  _bindEvents() {
    const canvas = this.renderer.domElement;
    const on = (target, type, fn, opts) => {
      target.addEventListener(type, fn, opts);
      this._listeners.push({ target, type, fn, opts });
    };

    on(canvas, 'mousedown',   e => { this._dragStart(e.clientX, e.clientY); this._ptrDownPt = { x: e.clientX, y: e.clientY }; });
    on(window, 'mousemove',   e => {
      if (this.isDragging) { this._dragMove(e.clientX, e.clientY); return; }
      this._updateNavHover(e);
    });
    on(window, 'mouseup',     e => {
      this._dragEnd();
      // Synthesise click: fire our handler when pointer barely moved (< 6px).
      // Replaces native 'click' which OrbitControls' preventDefault() suppresses
      // on pointerdown in dollhouse mode.
      if (this._ptrDownPt && e.button === 0) {
        const dx = e.clientX - this._ptrDownPt.x;
        const dy = e.clientY - this._ptrDownPt.y;
        if (dx * dx + dy * dy < 36) this._onCanvasClick(e);
      }
      this._ptrDownPt = null;
    });
    on(canvas, 'wheel',       e => { e.preventDefault(); this._zoom(e.deltaY * 0.05); this._resetAutoTimer(); }, { passive: false });
    on(canvas, 'dblclick',    () => this._zoom(-15));
    on(canvas, 'contextmenu', e => e.preventDefault());

    on(canvas, 'touchstart', e => { e.preventDefault(); this._onTouchStart(e); }, { passive: false });
    on(canvas, 'touchmove',  e => { e.preventDefault(); this._onTouchMove(e);  }, { passive: false });
    on(canvas, 'touchend',   e => { e.preventDefault(); this._onTouchEnd(e); }, { passive: false });
    on(canvas, 'touchcancel',e => { this._touches = []; this._dragEnd(); });

    on(window, 'keydown', e => this._onKey(e));

    // Prefer ResizeObserver on container; fall back to window resize
    if (typeof ResizeObserver !== 'undefined') {
      this._resizeObs = new ResizeObserver(() => this._onResize());
      this._resizeObs.observe(this.container);
    } else {
      on(window, 'resize', () => this._onResize());
    }

    // Consolidated fullscreen handler: resize + toggle icon
    on(document, 'fullscreenchange', () => {
      this._onResize();
      const inFull = document.fullscreenElement;
      this.fullscreenBtn.innerHTML = inFull ? ICONS.exitFull : ICONS.fullscreen;
    });

    // WebGL context loss/restore
    on(canvas, 'webglcontextlost', e => {
      e.preventDefault();
      console.warn('[PanoViewer] WebGL context lost');
      cancelAnimationFrame(this._raf);
    });
    on(canvas, 'webglcontextrestored', () => {
      console.info('[PanoViewer] WebGL context restored');
      // Re-upload current texture if any
      if (this.sphereMat.map) this.sphereMat.map.needsUpdate = true;
      this._loop();
    });
  }

  _dragStart(x, y) {
    this.isDragging = true;
    this.lastPtr = { x, y };
    this.vel = { yaw: 0, pitch: 0 };
    this.container.classList.add('dragging');
    this._resetAutoTimer();
  }

  _dragMove(x, y, sens = this.cfg.sensitivity) {
    const scale = this.fov / 100;
    const dx = (x - this.lastPtr.x) * sens * scale;
    const dy = (y - this.lastPtr.y) * sens * scale;
    this.vel.yaw   = dx;
    this.vel.pitch = dy;
    this.yaw  += dx;
    this.pitch = Math.max(-this.cfg.maxPitch, Math.min(this.cfg.maxPitch, this.pitch + dy));
    this.lastPtr = { x, y };
  }

  _dragEnd() {
    if (!this.isDragging) return;
    this.isDragging = false;
    this.container.classList.remove('dragging');
    this._resetAutoTimer();
  }

  _onTouchStart(e) {
    this._touches = Array.from(e.touches);
    if (this._touches.length === 1) {
      this._dragStart(this._touches[0].clientX, this._touches[0].clientY);
    } else if (this._touches.length === 2) {
      this.isDragging = false;
      this.container.classList.remove('dragging');
      this._pinchBase = this._pinchDist(this._touches);
    }
  }

  _onTouchMove(e) {
    const prevCount = this._touches.length;
    this._touches = Array.from(e.touches);

    // 2 → 1 handoff: restart drag from the remaining finger (no camera jump)
    if (prevCount === 2 && this._touches.length === 1) {
      this._dragStart(this._touches[0].clientX, this._touches[0].clientY);
      return;
    }

    if (this._touches.length === 1 && this.isDragging) {
      this._dragMove(this._touches[0].clientX, this._touches[0].clientY, this.cfg.touchSensitivity);
    } else if (this._touches.length === 2) {
      const d = this._pinchDist(this._touches);
      this._zoom((this._pinchBase - d) * 0.18);
      this._pinchBase = d;
    }
  }

  _onTouchEnd(e) {
    this._touches = Array.from(e.touches);
    if (this._touches.length === 0) {
      this._dragEnd();
    } else if (this._touches.length === 1) {
      // Finished pinch, still one finger on screen
      this._dragStart(this._touches[0].clientX, this._touches[0].clientY);
    }
  }

  _pinchDist(touches) {
    const dx = touches[0].clientX - touches[1].clientX;
    const dy = touches[0].clientY - touches[1].clientY;
    return Math.sqrt(dx * dx + dy * dy);
  }

  _onKey(e) {
    // Skip when typing in an input/textarea/contenteditable
    const t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;

    // For pan keys, use step-based but don't accumulate on key-repeat
    const panStep = e.repeat ? 1.2 : 3;
    const map = {
      ArrowLeft: () => { this.vel.yaw -= panStep; this._resetAutoTimer(); },
      ArrowRight:() => { this.vel.yaw += panStep; this._resetAutoTimer(); },
      ArrowUp:   () => { this.vel.pitch += panStep; this._resetAutoTimer(); },
      ArrowDown: () => { this.vel.pitch -= panStep; this._resetAutoTimer(); },
      a: () => { this.vel.yaw -= panStep; this._resetAutoTimer(); },
      d: () => { this.vel.yaw += panStep; this._resetAutoTimer(); },
      w: () => { this.vel.pitch += panStep; this._resetAutoTimer(); },
      s: () => { this.vel.pitch -= panStep; this._resetAutoTimer(); },
      '+': () => this._zoom(-12),
      '=': () => this._zoom(-12),
      '-': () => this._zoom(12),
      '_': () => this._zoom(12),
      f:   () => this._toggleFullscreen(),
      F:   () => this._toggleFullscreen(),
    };
    const handler = map[e.key];
    if (handler) { handler(); e.preventDefault(); }
  }

  _onResize() {
    const { clientWidth: w, clientHeight: h } = this.container;
    this.camera.aspect = w / h;
    this.camera.updateProjectionMatrix();
    this.renderer.setSize(w, h);
    this._resetAutoTimer();
  }

  // ── Zoom ───────────────────────────────────────────────────────────────────

  _zoom(delta) {
    // Pano mode and dollhouse mode track FOV independently — zoom in
    // dollhouse must NOT change the pano view (and vice versa).
    if (this._mode === 'dollhouse' && this._dollhouseCam) {
      const fov = Math.max(this.cfg.minFov, Math.min(this.cfg.maxFov, this._dollhouseCam.fov + delta));
      this._dollhouseCam.fov = fov;
      this._dollhouseCam.updateProjectionMatrix();
      this._showZoomToast(fov);
      return;
    }
    this.fov = Math.max(this.cfg.minFov, Math.min(this.cfg.maxFov, this.fov + delta));
    this.camera.fov = this.fov;
    this.camera.updateProjectionMatrix();
    this._showZoomToast(this.fov);
    const sc = this._scenes[this._activeId];
    if (sc) this._maybeUpgradeToHigh(sc);
  }

  /**
   * Per-face LOD upgrade. Loads only the high-res texture for the face the
   * user is currently looking at; queues the next-most-visible face after
   * that, and so on. Avoids the giant 6-face GPU upload that caused the
   * spike. Each face's HIGH texture is cached by URL in _hiFaceCache.
   */
  _maybeUpgradeToHigh(sc) {
    if (this._isMobile) return;
    if (!sc || sc.type !== 'cube' || !sc.faces) return;
    const lowFaces = sc.facesLow || sc.faces;
    if (sc.faces === lowFaces) return;
    const threshold = +(this.cfg.highLodFovDeg ?? 75);
    if (this.fov > threshold) return;
    if (!this._cubeGroup) return;

    // Order the 6 faces by visibility — the one centred in the camera frustum
    // first, then ring of neighbours, leaving the back face for last.
    const order = this._faceLoadOrder();
    if (!order.length) return;

    // Track upgraded faces per scene id. _activeFaceUpgrade is "in flight" so
    // we don't enqueue the same face twice.
    if (!this._upgradedFaces) this._upgradedFaces = new Map(); // sceneId → Set
    let done = this._upgradedFaces.get(sc.id);
    if (!done) { done = new Set(); this._upgradedFaces.set(sc.id, done); }

    const next = order.find(i => !done.has(i));
    if (next == null) return; // all six already at HIGH
    if (this._activeFaceUpgrade) return; // wait for in-flight

    const url = sc.faces[next];
    if (!url) { done.add(next); return; }

    this._activeFaceUpgrade = sc.id + '::' + next;
    this._loadFaceHigh(url, next).then(tex => {
      this._activeFaceUpgrade = null;
      // Bail if user navigated away to a different scene.
      if (this._activeId !== sc.id) return;
      // Find this face in the live cube and swap its material map.
      const face = this._cubeGroup?.children?.find(c => c.userData?.faceIndex === next);
      if (!face?.material) return;
      const oldMap = face.material.map;
      face.material.map = tex;
      face.material.needsUpdate = true;
      if (oldMap && oldMap !== tex) oldMap.dispose();
      done.add(next);
      // Chain the next face on idle so we don't stall the frame.
      (window.requestIdleCallback || requestAnimationFrame)(() => this._maybeUpgradeToHigh(sc));
    }).catch(() => {
      this._activeFaceUpgrade = null;
    });
  }

  /**
   * Camera-walk transition with dolly-zoom feel.
   *
   * The camera lives at origin. To fake "walking forward into the next
   * scene", we use TWO simultaneous tricks:
   *   1. OLD cube SCALES UP + slides forward (we're moving past / through it)
   *   2. NEW cube starts SCALED UP (we're far away from it) and shrinks to
   *      its real size while sliding in (arrival)
   * Both fade against each other. The combined motion reads as 3D depth
   * even without real geometry.
   */
  _startCubeTransition(oldGroup, newGroup, sc, worldOffset) {
    // Cancel any in-flight transition cleanly. The previous OLD might be
    // mid-fade — restore its materials before disposing.
    if (this._cubeTransition) {
      const t = this._cubeTransition;
      if (t.oldGroup !== newGroup) this.scene.remove(t.oldGroup);
      this._cubeTransition = null;
    }

    const dir = worldOffset.clone().normalize();
    const TRAVEL    = 180;     // viewer units moved during transition
    const OLD_SCALE = 1.55;    // peak scale on OLD as camera "passes through"
    const NEW_SCALE = 1.45;    // start scale on NEW (shrinks to 1 = arrival)

    const setTransparent = (group, transparent) => {
      group.traverse(c => {
        if (c.material) {
          c.material.transparent = transparent;
          if (!transparent) c.material.opacity = 1;
          c.material.needsUpdate = true;
        }
      });
    };
    setTransparent(oldGroup, true);
    setTransparent(newGroup, true);

    // Initial state for NEW: in front of us at scale 1.45, opacity 0.
    if (sc.pano_quat) {
      newGroup.quaternion.set(sc.pano_quat.x, sc.pano_quat.y, sc.pano_quat.z, sc.pano_quat.w);
    } else {
      newGroup.quaternion.identity();
    }
    newGroup.position.copy(dir).multiplyScalar(-TRAVEL);
    newGroup.scale.setScalar(NEW_SCALE);
    if (newGroup.parent !== this.scene) this.scene.add(newGroup);

    oldGroup.position.set(0, 0, 0);
    oldGroup.scale.setScalar(1);

    this._cubeGroup = newGroup;
    this.scene.background = new THREE.Color(0x000000);
    this.sphere.visible = false;

    this._cubeTransition = {
      start: performance.now(),
      duration: 460,
      oldGroup, newGroup, dir,
      travel: TRAVEL, oldScale: OLD_SCALE, newScale: NEW_SCALE,
    };
  }

  /** Per-frame transition update (called from _tick). */
  _stepCubeTransition() {
    const t = this._cubeTransition;
    if (!t) return;
    const now = performance.now();
    const k = Math.min(1, (now - t.start) / t.duration);
    // Smoothstep — slightly different curve from cubic; reads more "dolly".
    const e = k * k * (3 - 2 * k);

    // OLD: slide forward + scale up + fade out.
    t.oldGroup.position.copy(t.dir).multiplyScalar(t.travel * e);
    const oldS = 1 + (t.oldScale - 1) * e;
    t.oldGroup.scale.setScalar(oldS);

    // NEW: pull in to origin + shrink to scale 1 + fade in.
    t.newGroup.position.copy(t.dir).multiplyScalar(-t.travel * (1 - e));
    const newS = t.newScale + (1 - t.newScale) * e;
    t.newGroup.scale.setScalar(newS);

    // Crossfade with slight bias so peak overlap is at e ~= 0.55 (avoids
    // dead zone where both look ghostly).
    const oldOp = Math.max(0, 1 - e * 1.2);
    const newOp = Math.min(1, e * 1.2);
    t.oldGroup.traverse(c => { if (c.material) c.material.opacity = oldOp; });
    t.newGroup.traverse(c => { if (c.material) c.material.opacity = newOp; });

    if (k >= 1) {
      this.scene.remove(t.oldGroup);
      // Reset OLD so it's clean for any future role.
      t.oldGroup.position.set(0, 0, 0);
      t.oldGroup.scale.setScalar(1);
      // Finalise NEW — back to opaque, real size.
      t.newGroup.position.set(0, 0, 0);
      t.newGroup.scale.setScalar(1);
      t.newGroup.position.set(0, 0, 0);
      t.newGroup.traverse(c => {
        if (c.material) {
          c.material.transparent = false;
          c.material.opacity = 1;
          c.material.needsUpdate = true;
        }
      });
      this._cubeTransition = null;
    }
  }

  /** Determine which face index is centred in the current camera view. */
  _facingIndex() {
    if (!this._cubeGroup) return 4; // default front
    // Camera direction in world space.
    const wd = new THREE.Vector3();
    this.camera.getWorldDirection(wd);
    // Rotate into cube-local space (undo the bake quaternion).
    const inv = this._cubeGroup.quaternion.clone().invert();
    wd.applyQuaternion(inv);
    // Pick dominant axis. Cube face mapping: +Y=0 +X=1 +Z=2 -X=3 -Z=4 -Y=5.
    const ax = Math.abs(wd.x), ay = Math.abs(wd.y), az = Math.abs(wd.z);
    if (ay >= ax && ay >= az) return wd.y > 0 ? 0 : 5;
    if (ax >= ay && ax >= az) return wd.x > 0 ? 1 : 3;
    return wd.z > 0 ? 2 : 4;
  }

  /** Face load priority: facing → 4 sides → opposite. */
  _faceLoadOrder() {
    const f = this._facingIndex();
    const opposite = { 0: 5, 5: 0, 1: 3, 3: 1, 2: 4, 4: 2 }[f];
    const all = [0, 1, 2, 3, 4, 5];
    return [f, ...all.filter(i => i !== f && i !== opposite), opposite];
  }

  /** Load a single high-res face texture (cached by URL). */
  _loadFaceHigh(url, faceIndex) {
    if (!this._hiFaceCache) this._hiFaceCache = new LRUCache(48);
    const cached = this._hiFaceCache.get(url);
    if (cached) return Promise.resolve(cached);
    return new Promise((resolve, reject) => {
      new THREE.TextureLoader().load(url, tex => {
        tex.colorSpace = THREE.SRGBColorSpace;
        tex.anisotropy = 4;
        tex.flipY = true;
        // top/bottom faces in our skybox use a 90° texture rotation; mirror
        // here so the high-res tex aligns with the low-res it replaces.
        if (faceIndex === 0) { tex.rotation = Math.PI / 2; tex.center.set(0.5, 0.5); }
        if (faceIndex === 5) { tex.rotation = -Math.PI / 2; tex.center.set(0.5, 0.5); }
        tex.needsUpdate = true;
        try { this.renderer.initTexture(tex); } catch (_) {}
        this._hiFaceCache.set(url, tex);
        resolve(tex);
      }, undefined, reject);
    });
  }

  _showZoomToast(fov) {
    const f = fov ?? this.fov;
    const pct = Math.round((1 - (f - this.cfg.minFov) / (this.cfg.maxFov - this.cfg.minFov)) * 100);
    this.zoomToast.textContent = `${pct}%`;
    this.zoomToast.classList.add('show');
    clearTimeout(this._zoomToastTimer);
    this._zoomToastTimer = setTimeout(() => this.zoomToast.classList.remove('show'), 1400);
  }

  // ── Auto-rotate ────────────────────────────────────────────────────────────

  _resetAutoTimer() {
    this.autoRotating = false;
    clearTimeout(this._autoRotTimer);
    if (this.cfg.autoRotate) {
      this._autoRotTimer = setTimeout(() => { this.autoRotating = true; }, this.cfg.autoRotateDelay);
    }
  }

  // ── Render loop ────────────────────────────────────────────────────────────

  _loop() {
    this._raf = requestAnimationFrame(() => this._loop());
    this._tick();
    const cam = this._mode === 'dollhouse' && this._dollhouseCam ? this._dollhouseCam : this.camera;
    if (this._mode === 'dollhouse') this._dollhouseCtrls?.update();

    // Main scene render (full container)
    this.renderer.setScissorTest(false);
    this.renderer.setViewport(0, 0, this.container.clientWidth, this.container.clientHeight);
    this.renderer.render(this.scene, cam);

    // Mini dollhouse inset (lower-left) — only when we have a model AND we're
    // in pano mode (redundant in dollhouse mode, where main view is the model).
    if (this._miniCam && this._model && this._mode !== 'dollhouse') {
      this._renderMiniInset();
    }

    this._updateMeasureLabels(cam);
  }

  _tick() {
    this._animateMarkers?.();
    this._stepCubeTransition?.();
    if (this._mode === 'dollhouse') { this._updateHotspots(); return; }
    if (!this.isDragging) {
      this.yaw   += this.vel.yaw;
      this.pitch  = Math.max(-this.cfg.maxPitch, Math.min(this.cfg.maxPitch, this.pitch + this.vel.pitch));
      this.vel.yaw   *= this.cfg.damping;
      this.vel.pitch *= this.cfg.damping;
    }
    // Auto-rotate: only when idle, and scale speed by FOV so zoomed-in feels slower
    if (this.autoRotating && !this.isDragging) {
      this.yaw += this.cfg.autoRotateSpeed * (this.fov / this.cfg.defaultFov);
    }

    const yr = THREE.MathUtils.degToRad(this.yaw);
    const pr = THREE.MathUtils.degToRad(this.pitch);
    const cp = Math.cos(pr);
    this.camera.lookAt(
      -Math.sin(yr) * cp,
       Math.sin(pr),
      -Math.cos(yr) * cp,
    );

    // Compass: needle points north (yaw=0 direction)
    this.compassNeedle.style.transform = `rotate(${-this.yaw}deg)`;

    this._updateHotspots();
  }

  destroy() {
    cancelAnimationFrame(this._raf);
    clearTimeout(this._autoRotTimer);
    clearTimeout(this._zoomToastTimer);
    clearTimeout(this._errorTimer);
    clearInterval(this._hashTimer);

    this._textureCache.clear();
    this._resizeObs?.disconnect();

    // Remove all tracked listeners
    this._listeners.forEach(({ target, type, fn, opts }) => {
      target.removeEventListener(type, fn, opts);
    });
    this._listeners = [];

    // Three.js cleanup
    this.sphereMat.map?.dispose();
    this.sphereMat.dispose();
    this.sphere.geometry.dispose();
    this.renderer.dispose();
    this.renderer.domElement.remove();

    if (this._lastBlobUrl) URL.revokeObjectURL(this._lastBlobUrl);
    this._cubeBlobUrls?.forEach(u => URL.revokeObjectURL(u));

    // Dollhouse / measure
    this._dollhouseCtrls?.dispose();
    if (this._model)        this._disposeObject(this._model);
    if (this._markersGroup) this._disposeObject(this._markersGroup);
    this._clearMeasure?.();
  }

  // ── Scene API ──────────────────────────────────────────────────────────────

  addScene(cfg) {
    this._scenes[cfg.id] = cfg;
    this._addThumb(cfg);
    this._updateSceneStrip();
    return this;
  }

  loadScene(id) {
    const sc = this._scenes[id];
    if (!sc || this._transitioning) return this;
    if (id === this._activeId) return this; // short-circuit same scene reload
    this._transitioning = true;

    const prev = this._activeId;
    this._activeId = id;

    // Active thumb
    this.scenesEl.querySelectorAll('.pano-scene-thumb').forEach(el => {
      el.classList.toggle('active', el.dataset.id === id);
    });

    // Title
    // Title label intentionally hidden — scene name shown elsewhere if needed.
    // this.titleEl.textContent = sc.title; this.titleEl.hidden = false;

    // Initial view — only on first load. Subsequent navigations preserve
    // the user's current yaw/pitch so jumping to a neighbour feels like a
    // teleport into the same look-direction, not a snap to scene-default.
    if (!prev && sc.initialView) {
      this.yaw   = sc.initialView.yaw ?? 0;
      this.pitch = sc.initialView.pitch ?? 0;
    }
    if (sc.hfov != null) { this.fov = sc.hfov; this.camera.fov = this.fov; this.camera.updateProjectionMatrix(); }

    const applyCommon = () => {
      // No fade overlay on subsequent loads — keeps the old pano visible
      // until the new one is ready, then swaps in place. Looks like a
      // teleport rather than a black flash.
      this.fadeEl.classList.remove('in');
      this.sphereMat.opacity = 1;
      this._transitioning = false;

      // Hotspots
      this._clearHotspots();
      sc.hotspots?.forEach(h => this._addHotspot(h));

      this._showEmpty(false);
      this._hideLoading();

      // Preload neighbor scenes
      this._preloadNeighbors(sc);

      // Fire event + sync hash
      this._emit('scenechange', { id, scene: sc, prev });
      // Recolour dollhouse markers so the active scene reads as distinct.
      this._setActiveMarker?.(id);
      if (this.cfg.urlHashSync) this._writeHash();
    };

    const onErr = (err) => {
      // TextureLoader's onError fires with an Event (not an Error), which
      // stringifies as "[object Event]" and .message is undefined — hence
      // the useless "An unexpected error occurred". Pull the target URL off
      // the event so we can actually see which file broke.
      const url = err?.target?.src || err?.target?.currentSrc || 'unknown URL';
      const kind = err?.type || err?.name || 'error';
      console.error('[PanoViewer] Texture load failed:', { url, kind, err, scene: sc });
      this._transitioning = false;
      this._hideLoading();
      this.fadeEl.classList.remove('in');
      this._showError(`Failed to load: ${url.split('/').pop()} (${kind})`);
    };

    const doLoad = () => {
      // Only show the loading UI on the very first scene — once neighbors
      // are preloaded, scene transitions resolve nearly instantly from the
      // browser cache and the fade overlay is enough feedback.
      if (!prev) this._showLoading();

      // Apply the scene's bake quaternion (Y-up Three.js coords) to the
      // panorama mesh. After this, world directions in the model frame map
      // directly to viewer directions — markers / nav arrows can sit at
      // raw world relative offsets with no extra rotation math.
      const applyPanoOrientation = (mesh) => {
        if (!mesh) return;
        if (sc.pano_quat) {
          mesh.quaternion.set(
            sc.pano_quat.x, sc.pano_quat.y, sc.pano_quat.z, sc.pano_quat.w,
          );
        } else {
          mesh.quaternion.identity();
        }
      };

      if (sc.type === 'cube' && sc.faces?.length === 6) {
        // LOD: render LOW immediately for instant clicks. Per-face HIGH
        // upgrades happen in the background, only for the face the user
        // is looking at.
        const lowFaces = sc.facesLow || sc.faces;
        const lowKey   = `${sc.id}::low`;

        // Only run the cross-fade transition when the target cube was
        // already preloaded. Building cold = we already made the user wait
        // ~200 ms of decode + upload; stacking a 320 ms slide on top of
        // that just feels janky. Cold path: snap.
        const wasCached = this._cubeCache.has(lowKey);

        this._getOrBuildCube(sc.id, lowFaces, !!prev, 'low').then(group => {
          const prevScene = prev && this._scenes[prev];
          const offset = (prevScene && prevScene.position && sc.position)
            ? new THREE.Vector3(
                sc.position.x - prevScene.position.x,
                sc.position.y - prevScene.position.y,
                sc.position.z - prevScene.position.z,
              )
            : null;

          let inTransition = false;
          if (wasCached && this._cubeGroup && offset && offset.lengthSq() > 0.0001) {
            this._startCubeTransition(this._cubeGroup, group, sc, offset);
            inTransition = true;
          } else {
            if (this._cubeGroup && this._cubeGroup !== group) {
              this.scene.remove(this._cubeGroup);
            }
            this._cubeGroup = group;
            applyPanoOrientation(group);
            if (group.parent !== this.scene) this.scene.add(group);
            this.scene.background = new THREE.Color(0x000000);
            this.sphere.visible = false;
          }
          applyCommon();
          // Texture upload mid-transition stutters the slide. Wait until
          // the cross-fade finishes before kicking the per-face HIGH chain.
          if (inTransition) {
            setTimeout(() => {
              if (this._activeId === sc.id) this._maybeUpgradeToHigh(sc);
            }, 360);
          } else {
            this._maybeUpgradeToHigh(sc);
          }
        }).catch(onErr);
      } else {
        // Equirectangular: texture on sphere
        if (this._cubeGroup) {
          this.scene.remove(this._cubeGroup);
          this._disposeObject(this._cubeGroup);
          this._cubeGroup = null;
        }
        this._loadTexture(sc.image).then(tex => {
          this.scene.background = new THREE.Color(0x090910);
          this.sphere.visible = true;
          this.sphereMat.map = tex;
          this.sphereMat.map.colorSpace = THREE.SRGBColorSpace;
          this.sphereMat.color = new THREE.Color(0xffffff);
          this.sphereMat.needsUpdate = true;
          applyPanoOrientation(this.sphere);
          applyCommon();
        }).catch(onErr);
      }
    };

    // Lazy-swap: kick the load immediately. The previous cube/sphere stays
    // in the scene until the new one resolves, so the user sees the old
    // view until everything is ready — no overlay, no flash.
    if (!prev) this.sphereMat.opacity = 0;
    doLoad();

    this._resetAutoTimer();
    return this;
  }

  /** Load a single image directly without a scene config. */
  loadImage(url, title = '') {
    // Same dedupe as drop: replace previous ad-hoc scene to avoid strip clutter
    if (this._lastDropSceneId && this._scenes[this._lastDropSceneId]) {
      delete this._scenes[this._lastDropSceneId];
      const thumb = this.scenesEl.querySelector(`.pano-scene-thumb[data-id="${this._lastDropSceneId}"]`);
      thumb?.remove();
      if (this._activeId === this._lastDropSceneId) this._activeId = null;
    }
    const id = `_img_${Date.now()}`;
    this._lastDropSceneId = id;
    this.addScene({ id, title, image: url, hotspots: [] });
    this.loadScene(id);
    this._updateSceneStrip();
    return this;
  }

  /** Programmatic camera control. Accepts { yaw, pitch, fov }. */
  setView({ yaw, pitch, fov } = {}) {
    if (typeof yaw === 'number')   this.yaw = yaw;
    if (typeof pitch === 'number') this.pitch = Math.max(-this.cfg.maxPitch, Math.min(this.cfg.maxPitch, pitch));
    if (typeof fov === 'number') {
      this.fov = Math.max(this.cfg.minFov, Math.min(this.cfg.maxFov, fov));
      this.camera.fov = this.fov;
      this.camera.updateProjectionMatrix();
    }
    this.vel = { yaw: 0, pitch: 0 };
    this._resetAutoTimer();
    return this;
  }

  /** Return to the active scene's initial view (or 0,0,default). */
  resetView() {
    const sc = this._scenes[this._activeId];
    const init = sc?.initialView || {};
    this.setView({
      yaw:   init.yaw   ?? 0,
      pitch: init.pitch ?? 0,
      fov:   sc?.hfov   ?? this.cfg.defaultFov,
    });
    return this;
  }

  /** Get current camera state. */
  getView() {
    return { yaw: this.yaw, pitch: this.pitch, fov: this.fov, scene: this._activeId };
  }

  // ── Event bus ──────────────────────────────────────────────────────────────

  on(event, fn)  { (this._events ||= {})[event] ||= []; this._events[event].push(fn); return this; }
  off(event, fn) { const a = this._events?.[event]; if (!a) return; const i = a.indexOf(fn); if (i >= 0) a.splice(i, 1); return this; }
  _emit(event, data) { this._events?.[event]?.forEach(fn => { try { fn(data); } catch (e) { console.error(e); } }); }

  // ── URL hash sync ──────────────────────────────────────────────────────────

  _writeHash() {
    const h = `#${this._activeId}/${this.yaw.toFixed(1)}/${this.pitch.toFixed(1)}/${this.fov.toFixed(1)}`;
    history.replaceState(null, '', h);
  }

  _readHash() {
    const hash = location.hash.replace(/^#/, '');
    if (!hash) return null;
    const [id, yaw, pitch, fov] = hash.split('/');
    return {
      id,
      yaw:   parseFloat(yaw),
      pitch: parseFloat(pitch),
      fov:   parseFloat(fov),
    };
  }

  /**
   * Load scenes from a GoHéritage-exported JSON.
   * Each hotspot with a `panorama` field becomes a scene; nav hotspots
   * are auto-generated between scenes whose 3D positions are within
   * `maxLinkDistance` world units.
   *
   * @param {object} data    Parsed JSON from GoHéritage export
   * @param {object} [opts]
   * @param {'exterior'|'interior'} [opts.side='exterior']
   * @param {string}  [opts.baseUrl='']  Prepended to relative panorama paths
   * @param {number}  [opts.maxLinkDistance=8]  World units for auto-linking
   * @param {boolean} [opts.autoLoad=true]  Load the first scene after import
   */
  loadFromGoHeritage(data, opts = {}) {
    const {
      side = 'exterior',
      baseUrl = '',
      maxLinkDistance = 8,
      autoLoad = true,
    } = opts;

    const bucket = data?.[side] || data?.exterior || {};
    const hotspots = (bucket.hotspots || []).filter(h => h.panorama);

    if (!hotspots.length) {
      console.warn('[PanoViewer] GoHéritage: no hotspots with panorama on side:', side);
      return this;
    }

    // Build scenes
    const scenes = hotspots.map(h => {
      const url = baseUrl
        ? (baseUrl.replace(/\/$/, '') + '/' + h.panorama.replace(/^\.?\/?/, ''))
        : h.panorama;
      return {
        src: h,
        cfg: {
          id:          h.id,
          title:       h.title || h.id,
          image:       url,
          preview:     url,
          hotspots:    [],   // filled in next pass
          initialView: { yaw: h.pano_yaw ?? 0, pitch: h.pano_pitch ?? 0 },
          position:    h.position,   // for dollhouse markers
          pano_quat:   h.pano_quat,  // bake quaternion (Y-up); orients cube
        },
      };
    });

    // Auto-generate nav hotspots between nearby scenes.
    // Positions are in Three.js Y-up. Horizontal plane = (x, z), vertical = y.
    //
    // For each neighbour B of scene A we compute the relative offset (B − A),
    // then rotate it by −A.pano_yaw around the up axis so the offset is in A's
    // viewer space (where pano yaw 0 = -Z). The viewer renders the arrow at
    // that 3D position — keeping its real horizontal placement, not on a fixed
    // ring around the camera. The dy component is preserved so steps up/down
    // a level put the arrow visibly higher/lower on the floor.
    // World-space offsets. The pano mesh is rotated by sc.pano_quat at scene
    // load time (see applyPanoOrientation), so world == viewer for arrows.
    for (let i = 0; i < scenes.length; i++) {
      const A = scenes[i].src;
      const Apos = A.position || { x: 0, y: 0, z: 0 };
      for (let j = 0; j < scenes.length; j++) {
        if (i === j) continue;
        const B = scenes[j].src;
        const Bpos = B.position || { x: 0, y: 0, z: 0 };

        const dx = Bpos.x - Apos.x;
        const dy = Bpos.y - Apos.y;
        const dz = Bpos.z - Apos.z;
        const dist = Math.hypot(dx, dy, dz);
        if (dist > maxLinkDistance || dist < 0.01) continue;

        scenes[i].cfg.hotspots.push({
          id:     `${A.id}__to__${B.id}`,
          type:   'nav',
          target: B.id,
          label:  B.title || B.id,
          offset: { x: dx, y: dy, z: dz },
        });
      }
    }

    // Register + optionally load first
    scenes.forEach(s => this.addScene(s.cfg));
    if (autoLoad) this.loadScene(scenes[0].cfg.id);
    return this;
  }

  /**
   * Fetch + apply a GoHéritage JSON file in one call.
   */
  async loadFromGoHeritageURL(url, opts = {}) {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`GoHéritage JSON fetch failed: ${res.status}`);
    const data = await res.json();
    // Default baseUrl = directory of the JSON so panorama paths resolve relative to it
    const baseUrl = opts.baseUrl ?? url.substring(0, url.lastIndexOf('/'));
    return this.loadFromGoHeritage(data, { ...opts, baseUrl });
  }

  /** Apply initial view from #scene/yaw/pitch/fov hash if present. */
  applyHash() {
    const h = this._readHash();
    if (!h || !h.id || !this._scenes[h.id]) return false;
    this.loadScene(h.id);
    this.setView({ yaw: h.yaw, pitch: h.pitch, fov: h.fov });
    return true;
  }

  // ── Texture ────────────────────────────────────────────────────────────────

  _loadTexture(url, silent = false) {
    const cached = this._textureCache.get(url);
    if (cached) return Promise.resolve(cached);

    return new Promise((resolve, reject) => {
      new THREE.TextureLoader().load(
        url,
        tex => { this._textureCache.set(url, tex); resolve(tex); },
        ({ loaded, total }) => {
          if (!silent && total > 0) this.loadingBar.style.width = `${(loaded / total) * 100}%`;
        },
        reject,
      );
    });
  }

  /**
   * Build 6 inside-facing textured planes forming a skybox cube.
   * @param {Array<string|File>} faces  In Matterport skybox order [0..5]
   *        0=front (-Z), 1=right (+X), 2=back (+Z), 3=left (-X), 4=up (+Y), 5=down (-Y)
   * Returns a THREE.Group centered at origin, sized 1000 units.
   */
  _loadCubeMesh(faces, silent = false) {
    // Delegate to the standalone builder. Viewer still owns blob-URL
    // tracking (for revoke-on-swap) and the loading-bar UI.
    return buildCubeMesh(faces, {
      debug:            this.cfg.debug,
      blobUrlRegistry:  (this._cubeBlobUrls ||= new Set()),
      onProgress:       silent ? undefined : pct => { this.loadingBar.style.width = `${pct * 100}%`; },
    });
  }

  /**
   * Cached cube build at a given LOD level ('low' | 'high'). Resolves to a
   * fully-uploaded Group ready to drop into the scene with no first-frame
   * hitch. In-flight builds for the same key are de-duped.
   */
  _getOrBuildCube(sceneId, faces, silent = true, level = 'high') {
    const key = `${sceneId}::${level}`;
    if (this._cubeCache.has(key)) {
      return Promise.resolve(this._cubeCache.get(key));
    }
    if (this._cubeBuilds.has(key)) return this._cubeBuilds.get(key);

    const p = this._loadCubeMesh(faces, silent).then(group => {
      group.userData.lodLevel = level;
      group.userData.sceneId  = sceneId;
      // Force GPU upload of every face's texture now.
      group.traverse(c => {
        if (c.isMesh && c.material?.map) {
          try { this.renderer.initTexture(c.material.map); } catch (_) {}
        }
      });
      // Stealth-render once to a 1×1 viewport so shader compile + VAO setup
      // happens up front. Use a temp Scene so we don't dirty the live one.
      try {
        const tmp = new THREE.Scene();
        tmp.add(group);
        const prev = this.renderer.autoClear;
        this.renderer.autoClear = false;
        this.renderer.setScissorTest(true);
        this.renderer.setScissor(0, 0, 1, 1);
        this.renderer.setViewport(0, 0, 1, 1);
        this.renderer.render(tmp, this.camera);
        this.renderer.setScissorTest(false);
        this.renderer.autoClear = prev;
        tmp.remove(group);
      } catch (_) {}
      this._cubeCache.set(key, group);
      this._cubeBuilds.delete(key);
      return group;
    }).catch(err => {
      this._cubeBuilds.delete(key);
      throw err;
    });
    this._cubeBuilds.set(key, p);
    return p;
  }

  _preloadNeighbors(scene) {
    if (!this.cfg.preloadNeighbors || !scene.hotspots) return;
    // Run preloads SERIALLY — kicking 6 cube builds in parallel saturates
    // the renderer (each one runs initTexture + a stealth render at end),
    // and any pano transition or animation playing during those parallel
    // bursts visibly stutters. Queue them; cancel-and-restart whenever the
    // active scene changes.
    const queue = scene.hotspots
      .map(h => h.target && this._scenes[h.target])
      .filter(Boolean);

    const runId = ++this._preloadRunId;
    const next = () => {
      if (this._preloadRunId !== runId) return;
      const target = queue.shift();
      if (!target) return;
      let p;
      if (target.image) {
        p = this._loadTexture(target.image, true);
      } else if (target.type === 'cube' && Array.isArray(target.faces)) {
        const low = target.facesLow || target.faces;
        p = this._getOrBuildCube(target.id, low, true, 'low');
      } else {
        p = Promise.resolve();
      }
      p.catch(() => {}).then(() => {
        // Yield to the browser between preloads so transitions can grab
        // the main thread; chained immediately would block.
        (window.requestIdleCallback || requestAnimationFrame)(next);
      });
    };
    next();
  }

  _showLoading() {
    this.loadingEl.classList.remove('hidden');
    this.loadingBar.style.width = '0%';
  }

  _hideLoading() {
    this.loadingEl.classList.add('hidden');
  }

  _showEmpty(show) {
    this.emptyEl.style.display = show ? '' : 'none';
  }

  _showError(msg) {
    if (!this.errorEl) {
      this.errorEl = document.createElement('div');
      this.errorEl.className = 'pano-error-toast';
      this.errorEl.style.cssText = `
        position: absolute; bottom: 100px; left: 50%; transform: translateX(-50%);
        background: rgba(200, 50, 50, 0.9); backdrop-filter: blur(10px);
        border-radius: 6px; padding: 8px 14px; font-size: 12px;
        color: #fff; z-index: 25; white-space: nowrap;
        opacity: 0; transition: opacity 0.35s ease; pointer-events: none;
      `;
      this.container.appendChild(this.errorEl);
    }
    this.errorEl.textContent = msg;
    // Force reflow for transition to trigger
    void this.errorEl.offsetHeight;
    this.errorEl.style.opacity = '1';
    clearTimeout(this._errorTimer);
    this._errorTimer = setTimeout(() => {
      this.errorEl.style.opacity = '0';
    }, 3500);
  }

  // ── Hotspots ───────────────────────────────────────────────────────────────

  _clearHotspots() {
    this._hotspots.forEach(({ el }) => el.remove());
    this._hotspots = [];
    // Floor arrows live in the 3D scene
    if (this._navArrowsGroup) {
      this.scene.remove(this._navArrowsGroup);
      this._disposeObject(this._navArrowsGroup);
      this._navArrowsGroup = null;
    }
    this._navArrows = [];
  }

  _addHotspot(h) {
    // Matterport-style nav: render a floor-projected 3D marker at the target
    // scene's relative position. Click via raycast (see _onCanvasClick).
    if ((h.type || 'nav') === 'nav' && h.target && (h.offset || typeof h.yaw === 'number')) {
      this._addFloorArrow(h);
      return;
    }

    // Info/other hotspots keep the DOM dot
    const el = document.createElement('div');
    el.className = `pano-hotspot pano-hotspot-${h.type || 'nav'}`;
    el.innerHTML = `
      <div class="pano-hotspot-dot"></div>
      ${h.label ? `<div class="pano-hotspot-label">${h.label}</div>` : ''}`;

    el.addEventListener('click', () => {
      if (h.target)  this.loadScene(h.target);
      if (h.onClick) h.onClick(h);
    });

    this.hsLayer.appendChild(el);

    const yr = THREE.MathUtils.degToRad(h.yaw   ?? 0);
    const pr = THREE.MathUtils.degToRad(h.pitch ?? 0);
    const cp = Math.cos(pr);
    const vec3 = new THREE.Vector3(-Math.sin(yr) * cp, Math.sin(pr), -Math.cos(yr) * cp);

    this._hotspots.push({ el, vec3 });
  }

  _addFloorArrow(h) {
    if (!this._navDotTex) this._navDotTex = this._makeNavTexture();
    if (!this._navArrowsGroup) {
      this._navArrowsGroup = new THREE.Group();
      this._navArrowsGroup.name = 'navArrows';
      this.scene.add(this._navArrowsGroup);
    }

    if (!h.offset) return;
    const SIZE = 0.7;
    const EYE_H = +(this.cfg.panoEyeHeightMeters ?? 1.6);
    const x = +h.offset.x || 0;
    const z = +h.offset.z || 0;
    const oy = +h.offset.y || 0;
    const y = oy - EYE_H;

    const geo = new THREE.PlaneGeometry(SIZE, SIZE);
    const mat = new THREE.MeshBasicMaterial({
      map: this._navDotTex,
      transparent: true,
      opacity: 0.78,
      depthTest: false,
      depthWrite: false,
      side: THREE.DoubleSide,
    });
    const mesh = new THREE.Mesh(geo, mat);
    mesh.renderOrder = 5;
    mesh.position.set(x, y, z);
    mesh.rotation.x = -Math.PI / 2;

    // Animated hover state — _tick() lerps current → target each frame.
    mesh.userData.navTarget    = h.target;
    mesh.userData.navLabel     = h.label || h.target;
    mesh.userData.isNav        = true;
    mesh.userData.baseOpacity  = 0.78;
    mesh.userData.hoverOpacity = 1.0;
    mesh.userData.baseScale    = 1.0;
    mesh.userData.hoverScale   = 1.45;
    mesh.userData.targetScale  = 1.0;
    mesh.userData.targetOpacity = 0.78;
    mesh.layers.set(0);

    this._navArrowsGroup.add(mesh);
    this._navArrows.push(mesh);
  }

  /** Modern floor target: thin ring + small filled centre dot. */
  _makeNavTexture() {
    const SZ = 256;
    const canvas = document.createElement('canvas');
    canvas.width = SZ; canvas.height = SZ;
    const ctx = canvas.getContext('2d');
    const cx = SZ / 2, cy = SZ / 2;

    // Soft drop shadow so the marker reads against any pano content
    ctx.shadowColor   = 'rgba(0, 0, 0, 0.45)';
    ctx.shadowBlur    = 14;
    ctx.shadowOffsetY = 3;

    // Outer ring (the main visual)
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.95)';
    ctx.lineWidth   = 8;
    ctx.beginPath();
    ctx.arc(cx, cy, 92, 0, Math.PI * 2);
    ctx.stroke();

    // Reset shadow for the inside fills so they don't get a halo
    ctx.shadowColor   = 'transparent';
    ctx.shadowBlur    = 0;
    ctx.shadowOffsetY = 0;

    // Faint translucent fill so the disk reads as a "target", not a hole
    ctx.fillStyle = 'rgba(255, 255, 255, 0.18)';
    ctx.beginPath();
    ctx.arc(cx, cy, 88, 0, Math.PI * 2);
    ctx.fill();

    // Centre dot — small solid focus
    ctx.fillStyle = 'rgba(255, 255, 255, 0.95)';
    ctx.beginPath();
    ctx.arc(cx, cy, 14, 0, Math.PI * 2);
    ctx.fill();

    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = 4;
    return tex;
  }

  /** Mousemove → raycast nav arrows + dollhouse markers, set hover targets. */
  _updateNavHover(e) {
    if (this._measure?.active) return;
    const rect = this.renderer.domElement.getBoundingClientRect();
    const off = e.clientX < rect.left || e.clientX > rect.right ||
                e.clientY < rect.top  || e.clientY > rect.bottom;

    let next = null;
    if (!off) {
      if (this._mode === 'pano' && this._navArrows?.length) {
        const hit = this._raycastFromEvent(e, this._navArrows);
        next = hit?.object || null;
      } else if (this._mode === 'dollhouse' && this._markersGroup) {
        // Markers live on layer 1 — temporarily enable for the raycast.
        this._raycaster.layers.enableAll();
        const hit = this._raycastFromEvent(e, this._markersGroup.children);
        this._raycaster.layers.set(0);
        // Find the wrap (Group) parent so we can scale the whole marker.
        let wrap = hit?.object || null;
        while (wrap && !wrap.userData?.haloMat) wrap = wrap.parent;
        next = wrap || null;
      }
    }

    if (next === this._hoveredNav) return;
    // Clear previous
    if (this._hoveredNav) {
      const u = this._hoveredNav.userData;
      u.targetScale   = u.baseScale   ?? 1;
      u.targetOpacity = u.baseOpacity ?? 1;
    }
    this._hoveredNav = next;
    if (next) {
      const u = next.userData;
      u.targetScale   = u.hoverScale   ?? 1.4;
      u.targetOpacity = u.hoverOpacity ?? 1;
      this.renderer.domElement.style.cursor = 'pointer';
    } else {
      this.renderer.domElement.style.cursor = '';
    }
  }

  /** Per-frame easing for nav arrows + dollhouse markers. */
  _animateMarkers() {
    const k = 0.18; // smoothing factor — higher = snappier
    const apply = (mesh) => {
      const u = mesh.userData;
      const targetScale = u.targetScale ?? u.baseScale ?? 1;
      const cur = mesh.scale.x;
      if (Math.abs(cur - targetScale) > 0.001) {
        const next = cur + (targetScale - cur) * k;
        mesh.scale.setScalar(next);
      }
      // Opacity lerp on a single material, or both halo+core for wraps.
      const target = u.targetOpacity ?? u.baseOpacity ?? 1;
      const lerpMat = (mat, weight) => {
        if (!mat) return;
        const cur2 = mat.opacity;
        const want = target * weight;
        if (Math.abs(cur2 - want) > 0.005) {
          mat.opacity = cur2 + (want - cur2) * k;
        }
      };
      if (mesh.material) {
        lerpMat(mesh.material, 1);
      } else {
        // Glow tracks at ~30% of the ring's opacity so it stays subtle.
        lerpMat(u.coreMat, 1);
        lerpMat(u.haloMat, 0.3);
      }
    };
    this._navArrows?.forEach(apply);
    this._markersGroup?.children?.forEach(apply);
  }

  _updateHotspots() {
    if (!this._hotspots.length) return;
    const { clientWidth: w, clientHeight: h } = this.container;

    const camDir = new THREE.Vector3();
    this.camera.getWorldDirection(camDir);

    const tmp = new THREE.Vector3();

    this._hotspots.forEach(({ el, vec3 }) => {
      const inFront = camDir.dot(vec3) > 0.1;

      if (!inFront) { el.style.display = 'none'; return; }

      tmp.copy(vec3).project(this.camera);
      el.style.display = '';
      el.style.left = `${(tmp.x * 0.5 + 0.5) * w}px`;
      el.style.top  = `${(-tmp.y * 0.5 + 0.5) * h}px`;
    });
  }

  // ── Scene strip ────────────────────────────────────────────────────────────

  _addThumb(sc) {
    const el = document.createElement('div');
    el.className = 'pano-scene-thumb';
    el.dataset.id = sc.id;

    const src = sc.preview || sc.image;
    if (src) {
      const img = document.createElement('img');
      img.src = src; img.loading = 'lazy';
      img.alt = sc.title || 'Scene';
      el.appendChild(img);
    }
    el.setAttribute('role', 'button');
    el.setAttribute('aria-label', `Go to ${sc.title || 'scene'}`);
    if (sc.title) {
      const lbl = document.createElement('div');
      lbl.className = 'pano-scene-thumb-label';
      lbl.textContent = sc.title;
      el.appendChild(lbl);
    }

    el.addEventListener('click', () => this.loadScene(sc.id));
    this.scenesEl.appendChild(el);
  }

  _updateSceneStrip() {
    // Scene strip intentionally hidden — nav is via floor arrows + mini dollhouse.
    this.scenesEl.hidden = true;
  }

  // ── Drop zone ──────────────────────────────────────────────────────────────

  _setupDrop() {
    const on = (target, type, fn, opts) => {
      target.addEventListener(type, fn, opts);
      this._listeners.push({ target, type, fn, opts });
    };
    on(this.container, 'dragover', e => {
      e.preventDefault();
      this.dropEl.classList.add('active');
    });
    on(this.container, 'dragleave', e => {
      if (!this.container.contains(e.relatedTarget)) this.dropEl.classList.remove('active');
    });
    on(this.container, 'drop', e => {
      e.preventDefault();
      this.dropEl.classList.remove('active');
      const files = Array.from(e.dataTransfer?.files || []).filter(f => f.type.startsWith('image/'));
      if (!files.length) return;

      if (files.length === 1) {
        // Single file → existing equirect path with blobUrl dedupe
        if (this._lastBlobUrl) URL.revokeObjectURL(this._lastBlobUrl);
        const url = URL.createObjectURL(files[0]);
        this._lastBlobUrl = url;
        this.loadImage(url, files[0].name.replace(/\.[^/.]+$/, ''));
      } else {
        // Multi-file → auto-detect cube groups + equirect panos
        this.importFiles(files);
      }
    });
  }

  /**
   * Auto-detect + import a batch of files (cube skybox groups and/or equirect images).
   * Replaces any prior dropped scenes.
   */
  importFiles(files) {
    const { cube, equirect, incomplete } = detectPanoramaGroups(files);

    // Remove prior drop-based scenes + release any prior cube blob URLs
    this._clearDropScenes();

    const newScenes = [];
    // Cube groups first
    for (const [prefix, faces] of Object.entries(cube)) {
      const id = `_cube_${prefix}`;
      newScenes.push({
        id,
        title: prefix,
        type:  'cube',
        faces, // File[] (will become blob URLs in _loadCubeTexture)
        preview: URL.createObjectURL(faces[0]),
        hotspots: [],
      });
    }
    // Equirect images
    for (const f of equirect) {
      const url = URL.createObjectURL(f);
      (this._cubeBlobUrls ||= new Set()).add(url);
      newScenes.push({
        id:    `_eq_${f.name}`,
        title: f.name.replace(/\.[^/.]+$/, ''),
        image: url,
        preview: url,
        hotspots: [],
      });
    }

    if (!newScenes.length) {
      this._showError('No valid panoramas detected.');
      return this;
    }

    // Track as drop-scenes for later cleanup
    this._dropSceneIds = newScenes.map(s => s.id);
    newScenes.forEach(s => this.addScene(s));
    this.loadScene(newScenes[0].id);

    if (Object.keys(incomplete).length) {
      console.warn('[PanoViewer] Incomplete skybox groups skipped:', incomplete);
    }

    return this;
  }

  _clearDropScenes() {
    if (this._dropSceneIds?.length) {
      this._dropSceneIds.forEach(id => {
        delete this._scenes[id];
        this.scenesEl.querySelector(`.pano-scene-thumb[data-id="${id}"]`)?.remove();
        if (this._activeId === id) this._activeId = null;
      });
      this._dropSceneIds = [];
    }
    // Revoke any cube blob URLs
    if (this._cubeBlobUrls) {
      this._cubeBlobUrls.forEach(u => URL.revokeObjectURL(u));
      this._cubeBlobUrls.clear();
    }
    this._updateSceneStrip();
  }

  /**
   * Bulk-import a flat URL list of Matterport-style skybox files.
   * Auto-detects groups of 6 by prefix + builds one scene per group.
   * @param {string[]} urls
   * @param {object} [opts]
   * @param {boolean} [opts.autoLink=true]  Add sequential nav hotspots between scenes
   */
  loadMatterportDir(urls, { autoLink = true } = {}) {
    const { cube, equirect, incomplete } = detectPanoramaGroups(urls);

    const scenes = [];
    for (const [prefix, faces] of Object.entries(cube)) {
      scenes.push({
        id:      prefix,
        title:   prefix.slice(0, 8),
        type:    'cube',
        faces,
        preview: faces[0],
        hotspots: [],
      });
    }
    for (const url of equirect) {
      const name = url.split(/[\\/]/).pop().replace(/\.[^/.]+$/, '');
      scenes.push({ id: name, title: name, image: url, preview: url, hotspots: [] });
    }

    // Optional sequential linking (basic next/prev arrows — no 3D positions here)
    if (autoLink && scenes.length > 1) {
      scenes.forEach((s, i) => {
        const next = scenes[(i + 1) % scenes.length];
        const prev = scenes[(i - 1 + scenes.length) % scenes.length];
        s.hotspots.push(
          { id: `${s.id}_next`, yaw:  0,   pitch: -20, type: 'nav', target: next.id, label: 'Next' },
          { id: `${s.id}_prev`, yaw:  180, pitch: -20, type: 'nav', target: prev.id, label: 'Prev' },
        );
      });
    }

    scenes.forEach(s => this.addScene(s));
    if (scenes.length) this.loadScene(scenes[0].id);

    if (Object.keys(incomplete).length) {
      console.warn('[PanoViewer] Incomplete skybox groups skipped:', incomplete);
    }
    return this;
  }

  // ── 3D model / Dollhouse ───────────────────────────────────────────────────

  /**
   * Load a 3D model (OBJ or GLTF/GLB) for dollhouse view + measurement.
   * @param {string} url
   * @param {object} [opts]
   * @param {'obj'|'gltf'} [opts.format]  Auto-detected from extension if omitted.
   * @param {string}       [opts.texture] Optional single texture URL applied to all meshes
   *                                      (useful for OBJ, or GLBs converted from OBJ with no embedded texture).
   * @param {string}       [opts.normal]  Optional normal map URL (ignored when using MeshBasicMaterial).
   * @param {boolean}      [opts.flipY=false]  TextureLoader flipY (OBJ usually false).
   * @param {number}       [opts.side=THREE.DoubleSide]  Material side.
   */
  loadModel(url, opts = {}) {
    const ext = (opts.format || url.split('.').pop().toLowerCase()).replace(/\?.*$/, '');
    const isGltf = ext === 'gltf' || ext === 'glb';
    const {
      texture: texUrl = null,
      flipY = false,
      side = THREE.FrontSide,   // FrontSide → backfaces are culled (transparent from wrong side)
    } = opts;

    return new Promise((resolve, reject) => {
      this._showLoading();
      const onProgress = ({ loaded, total }) => {
        if (total > 0) this.loadingBar.style.width = `${(loaded / total) * 100}%`;
      };
      const onLoad = root => {
        // GLTF gives { scene }, OBJ gives a Group directly
        const inner = isGltf ? root.scene : root;

        // Wrap in a parent group so we can apply Blender→Three coordinate
        // conversion without mutating the original transform.
        // Blender is Z-up, Three.js is Y-up → rotate -90° around X.
        const obj = new THREE.Group();
        obj.name = 'dollhouseModel';
        inner.rotation.x = -Math.PI / 2;
        obj.add(inner);

        // Remove previous
        if (this._model) {
          this.scene.remove(this._model);
          this._disposeObject(this._model);
        }
        this._model = obj;
        this._modelMeshes = [];
        inner.traverse(c => { if (c.isMesh) this._modelMeshes.push(c); });

        // Merge all child geometries into a single mesh when one shared
        // material applies. Building OBJs commonly have hundreds of mesh
        // groups (one per material in source); each adds frustum-culling
        // overhead, draw calls, BVH cost, and CPU-GPU sync. Collapsing to
        // one BufferGeometry slashes per-frame work and is the single
        // biggest factor in eliminating the post-load lag spike.
        const mergeIfBeneficial = (sharedMat) => {
          if (!sharedMat || this._modelMeshes.length < 2) return;
          try {
            // Force matrices fresh so each child's matrixWorld reflects the
            // inner.rotation (Z-up→Y-up swap) we set above.
            inner.updateMatrixWorld(true);
            const geoms = [];
            this._modelMeshes.forEach(m => {
              if (!m.geometry) return;
              // Bake world transform into geometry; we reset inner.rotation
              // afterwards so the swap doesn't get applied twice.
              const g = m.geometry.clone();
              g.applyMatrix4(m.matrixWorld);
              geoms.push(g.index ? (g.toNonIndexed?.() || g) : g);
            });
            // Drop attributes not present on every geometry — required by merge.
            const keep = new Set(['position', 'normal', 'uv']);
            geoms.forEach(g => {
              Object.keys(g.attributes).forEach(k => {
                if (!keep.has(k)) g.deleteAttribute(k);
              });
            });
            const merged = BufferGeometryUtils.mergeGeometries(geoms, false);
            if (!merged) return;
            // Replace inner contents with one big mesh that already lives in
            // world (Y-up) coordinates.
            while (inner.children.length) {
              const c = inner.children[0];
              inner.remove(c);
              this._disposeObject(c);
            }
            inner.rotation.set(0, 0, 0); // baked in
            // Precompute bounding box / sphere on the merged geometry so
            // setFromObject + raycasts skip the per-vertex re-walk later.
            merged.computeBoundingBox();
            merged.computeBoundingSphere();
            const mesh = new THREE.Mesh(merged, sharedMat);
            mesh.layers.set(1);
            mesh.frustumCulled = true;
            inner.add(mesh);
            this._modelMeshes = [mesh];
          } catch (e) {
            console.warn('[PanoViewer] geometry merge failed (non-fatal):', e);
          }
        };

        const finishUp = (sharedMat) => {
          this._modelMeshes.forEach(m => {
            const mats = Array.isArray(m.material) ? m.material : [m.material];
            mats.forEach(mm => { if (mm) mm.side = side; });
          });

          // Spread the heavy post-load work across many frames so any pano
          // transition the user has in flight stays smooth. Each step is
          // ≤ ~30 ms on a typical model; the browser can paint + animate
          // between RAFs.
          const yield_ = () => new Promise(r => requestAnimationFrame(r));
          (async () => {
            await yield_();
            mergeIfBeneficial(sharedMat); // collapse 200 sub-meshes → 1
            await yield_();
            obj.traverse(c => c.layers.set(1));
            obj.visible = true;
            this.scene.add(obj);
            await yield_();
            this._modelBounds = new THREE.Box3().setFromObject(obj);
            await yield_();
            this.dollhouseBtn.hidden = false;
            this.dollhouseEl.hidden  = false;
            this.measureBtn.hidden   = false;
            this._setupMiniDollhouse();
            await yield_();
            // Compile shaders (bypasses first-frame compile stall).
            try { this.renderer.compile(this.scene, this._miniCam || this.camera); } catch (_) {}
            await yield_();
            // One real render with the mini cam at its actual viewport size,
            // behind a scissor — forces texture upload now without dirtying
            // the live viewport.
            try {
              if (this._miniCam && this._miniRect) {
                const prev = this.renderer.autoClear;
                this.renderer.autoClear = false;
                this.renderer.setScissorTest(true);
                this.renderer.setScissor(0, 0, this._miniRect.w, this._miniRect.h);
                this.renderer.setViewport(0, 0, this._miniRect.w, this._miniRect.h);
                this.renderer.render(this.scene, this._miniCam);
                this.renderer.setScissorTest(false);
                this.renderer.autoClear = prev;
              }
            } catch (_) {}
            this._hideLoading();
            this._emit('modelloaded', { url });
            resolve(obj);
          })();
        };

        if (texUrl) {
          // Promise-wrapped texture load so we add to scene only after decode.
          new THREE.TextureLoader().load(
            texUrl,
            tex => {
              tex.colorSpace = THREE.SRGBColorSpace;
              tex.flipY = flipY;
              tex.needsUpdate = true;
              const mat = new THREE.MeshBasicMaterial({ map: tex, side, transparent: false });
              this._modelMeshes.forEach(m => {
                if (m.material) {
                  (Array.isArray(m.material) ? m.material : [m.material]).forEach(mm => mm.dispose?.());
                }
                m.material = mat;
              });
              // Force GPU upload now so the first interactive frame doesn't
              // stall on the texture bind.
              try { this.renderer.initTexture(tex); } catch (_) {}
              finishUp(mat);
            },
            undefined,
            err => onErr(err),
          );
        } else {
          finishUp(null);
        }
      };
      const onErr = err => {
        this._hideLoading();
        this._showError(`Model load failed: ${err.message || err}`);
        reject(err);
      };

      let loader;
      if (isGltf) {
        loader = new GLTFLoader();
        loader.setDRACOLoader(getDracoLoader(this.cfg.dracoPath));
        loader.setMeshoptDecoder(MeshoptDecoder);
      } else {
        loader = new OBJLoader();
      }
      loader.load(url, onLoad, onProgress, onErr);
    });
  }

  _onCanvasClick(e) {
    // Mini-dollhouse click region (pano mode, lower-left)
    if (this._mode === 'pano' && this._miniRect && this._model) {
      const rect = this.renderer.domElement.getBoundingClientRect();
      const px = e.clientX - rect.left;
      const py = e.clientY - rect.top;
      const { x, y, w, h } = this._miniRect;
      if (px >= x && px <= x + w && py >= y && py <= y + h) {
        this.setMode('dollhouse');
        return;
      }
    }

    // Pano floor-arrow nav click
    if (this._mode === 'pano' && !this._measure.active && this._navArrows?.length) {
      const hit = this._raycastFromEvent(e, this._navArrows);
      if (hit) {
        const target = hit.object.userData.navTarget;
        if (target) { this.loadScene(target); return; }
      }
    }

    // Dollhouse: click marker → teleport to pano. Markers live on layer 1
    // (so the pano camera ignores them); raycaster defaults to layer 0, so
    // we temporarily enable all layers.
    if (this._mode === 'dollhouse' && !this._measure.active && this._markersGroup) {
      this._raycaster.layers.enableAll();
      const hit = this._raycastFromEvent(e, this._markersGroup.children);
      this._raycaster.layers.set(0);
      if (hit) {
        const id = hit.object.userData.sceneId;
        if (id) {
          this.setMode('pano');
          this.loadScene(id);
          return;
        }
      }
    }

    if (!this._measure.active) return;

    // Measure: raycast against loaded model.
    if (!this._modelMeshes.length) {
      this._showError('No 3D model loaded for measurement');
      return;
    }
    this._raycaster.layers.enableAll();
    let hit;
    if (this._mode === 'pano') {
      // The pano camera sits at world (0, 0, 0) but the user is conceptually
      // located at the active scene's sweep position. Cast with the same
      // direction but origin shifted to that world point so the ray meets
      // the model where the user expects.
      const sc = this._scenes[this._activeId];
      if (!sc?.position) {
        this._raycaster.layers.set(0);
        this._showError('No position for active scene — measurement disabled');
        return;
      }
      const ov = this._markerOffset || { x: 0, y: 0, z: 0 };
      const rect = this.renderer.domElement.getBoundingClientRect();
      const ndc = new THREE.Vector2(
        ((e.clientX - rect.left) / rect.width)  * 2 - 1,
        -((e.clientY - rect.top)  / rect.height) * 2 + 1,
      );
      this._raycaster.setFromCamera(ndc, this.camera);
      this._raycaster.ray.origin.set(
        sc.position.x + ov.x,
        sc.position.y + ov.y,
        sc.position.z + ov.z,
      );
      const hits = this._raycaster.intersectObjects(this._modelMeshes, true);
      hit = hits[0] || null;
    } else {
      hit = this._raycastFromEvent(e, this._modelMeshes);
    }
    this._raycaster.layers.set(0);
    if (!hit) return;

    if (this._measure.points.length >= 2) this._clearMeasure();
    this._measure.points.push(hit.point.clone());

    if (this._measure.points.length === 2) {
      this._drawMeasureLine();
    } else {
      // First point marker as small label
      this._addMeasureLabel(this._measure.points[0], '•');
    }
  }

  _raycastFromEvent(e, targets) {
    const rect = this.renderer.domElement.getBoundingClientRect();
    const ndc = new THREE.Vector2(
      ((e.clientX - rect.left) / rect.width)  * 2 - 1,
      -((e.clientY - rect.top)  / rect.height) * 2 + 1,
    );
    const cam = this._mode === 'dollhouse' && this._dollhouseCam ? this._dollhouseCam : this.camera;
    this._raycaster.setFromCamera(ndc, cam);
    const hits = this._raycaster.intersectObjects(targets, true);
    return hits[0] || null;
  }

  _disposeObject(obj) {
    obj.traverse(c => {
      if (c.geometry) c.geometry.dispose();
      if (c.material) {
        if (Array.isArray(c.material)) c.material.forEach(m => m.dispose());
        else c.material.dispose();
      }
    });
  }

  // ── Fullscreen ─────────────────────────────────────────────────────────────

  _toggleFullscreen() {
    const root = document.fullscreenElement;
    if (root) {
      document.exitFullscreen?.();
    } else {
      (this.container.parentElement || document.documentElement).requestFullscreen?.();
    }
  }
}

// Apply mixins — keeps the class file smaller while sharing Three.js imports.
Object.assign(PanoViewer.prototype, measureMixin);
Object.assign(PanoViewer.prototype, dollhouseMixin);
