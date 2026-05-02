// Dollhouse mode mixin — mixed into PanoViewer.prototype.
// Handles: full dollhouse camera + OrbitControls, mini inset render,
// pano-position markers, and mode switching.
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

export const dollhouseMixin = {

  /** Switch between 'pano' and 'dollhouse' modes. */
  setMode(mode) {
    if (mode === this._mode) return this;
    if (mode === 'dollhouse' && !this._model) {
      this._showError('Load a 3D model first (viewer.loadModel)');
      return this;
    }
    // Clear any pending hover state — the meshes / wraps it points at may
    // belong to the mode we're leaving and get disposed.
    this._hoveredNav = null;
    if (this.renderer?.domElement) this.renderer.domElement.style.cursor = '';
    // Coming out of dollhouse, the pano camera was tilted down to look at
    // the model. Reset pitch to horizon so the next pano scene shows
    // forward, not floor. Yaw is preserved so direction-of-interest sticks.
    if (mode === 'pano' && this._mode === 'dollhouse') {
      this.pitch = 0;
    }
    this._mode = mode;
    this.dollhouseBtn.classList.toggle('toggled', mode === 'dollhouse');
    if (mode === 'dollhouse') this._enterDollhouse();
    else                      this._exitDollhouse();
    this._emit('modechange', { mode });
    return this;
  },

  _enterDollhouse() {
    this._panoBackground  = this.scene.background;
    this.scene.background = new THREE.Color(0x14141a);

    const bbox   = this._modelBounds || new THREE.Box3().setFromObject(this._model);
    const center = bbox.getCenter(new THREE.Vector3());
    const size   = bbox.getSize(new THREE.Vector3());
    const maxDim = Math.max(size.x, size.y, size.z);
    const fov    = 55;
    const fitH   = (maxDim * 0.5) / Math.tan((fov * Math.PI / 180) / 2);
    const dist   = fitH * 1.15;

    const { clientWidth: w, clientHeight: h } = this.container;
    this._dollhouseCam = new THREE.PerspectiveCamera(fov, w / h, 0.1, Math.max(1000, maxDim * 10));
    this._dollhouseCam.layers.disable(0);
    this._dollhouseCam.layers.enable(1);
    this._dollhouseCam.position.set(
      center.x + dist * 0.7,
      center.y + dist * 0.55,
      center.z + dist * 0.7,
    );
    this._dollhouseCam.lookAt(center);

    this._dollhouseCtrls = new OrbitControls(this._dollhouseCam, this.renderer.domElement);
    this._dollhouseCtrls.target.copy(center);
    this._dollhouseCtrls.enableDamping = true;
    this._dollhouseCtrls.dampingFactor = 0.1;
    this._dollhouseCtrls.minDistance   = maxDim * 0.3;
    this._dollhouseCtrls.maxDistance   = maxDim * 5;

    if (!this._dollhouseLights) {
      const amb = new THREE.AmbientLight(0xffffff, 0.7);
      const dir = new THREE.DirectionalLight(0xffffff, 0.8);
      dir.position.set(1, 2, 1);
      this.scene.add(amb, dir);
      this._dollhouseLights = [amb, dir];
    }

    // Mini-inset's white sphere marker would otherwise stay visible in
    // dollhouse mode (it's on layer 1 and never gets toggled outside the
    // inset render path). Hide it whenever we enter the full dollhouse.
    if (this._miniMarker) this._miniMarker.visible = false;

    this._buildPanoMarkers();
    this._logScaleDiagnostics(bbox);

    // Hide pano-only UI
    this.hsLayer.style.display     = 'none';
    this.compassEl.style.display   = 'none';
    this.dollhouseEl.style.display = 'none';

    // Exit button (created once, reused)
    if (!this._dollhouseExitBtn) {
      const btn = document.createElement('button');
      btn.className = 'pano-dollhouse-exit';
      btn.type = 'button';
      btn.innerHTML = `<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg><span>Retour panorama</span>`;
      btn.addEventListener('click', () => this.setMode('pano'));
      this.container.appendChild(btn);
      this._dollhouseExitBtn = btn;
    }
    this._dollhouseExitBtn.hidden = false;
  },

  /**
   * Print a scale-vs-position report so coordinate-system mismatches are
   * obvious. Useful when Matterport sweep coords don't visually line up with
   * the Blender model — the bounds + position min/max ranges should overlap
   * if both are in meters and same handedness.
   */
  _logScaleDiagnostics(bbox) {
    const min = bbox.min, max = bbox.max;
    const size = bbox.getSize(new THREE.Vector3());
    const positions = Object.values(this._scenes)
      .filter(s => s.position)
      .map(s => s.position);

    // Both positions and model bbox now in Three.js Y-up.
    let pmin = null, pmax = null;
    if (positions.length) {
      pmin = { x: Infinity, y: Infinity, z: Infinity };
      pmax = { x: -Infinity, y: -Infinity, z: -Infinity };
      positions.forEach(p => {
        pmin.x = Math.min(pmin.x, p.x); pmax.x = Math.max(pmax.x, p.x);
        pmin.y = Math.min(pmin.y, p.y); pmax.y = Math.max(pmax.y, p.y);
        pmin.z = Math.min(pmin.z, p.z); pmax.z = Math.max(pmax.z, p.z);
      });
    }

    console.group('[PanoViewer] Dollhouse scale diagnostics');
    console.log('Model bounds (meters):', {
      min: { x: +min.x.toFixed(2), y: +min.y.toFixed(2), z: +min.z.toFixed(2) },
      max: { x: +max.x.toFixed(2), y: +max.y.toFixed(2), z: +max.z.toFixed(2) },
      size: { x: +size.x.toFixed(2), y: +size.y.toFixed(2), z: +size.z.toFixed(2) },
    });
    console.log('Sweep positions (count):', positions.length);
    if (pmin) {
      console.log('Sweep bounds (meters):', {
        min: { x: +pmin.x.toFixed(2), y: +pmin.y.toFixed(2), z: +pmin.z.toFixed(2) },
        max: { x: +pmax.x.toFixed(2), y: +pmax.y.toFixed(2), z: +pmax.z.toFixed(2) },
      });
      // Overlap test — if any axis range completely misses, warn
      const miss =
        (pmax.x < min.x || pmin.x > max.x) ||
        (pmax.y < min.y || pmin.y > max.y) ||
        (pmax.z < min.z || pmin.z > max.z);
      if (miss) {
        console.warn('Sweep bounds DO NOT overlap model bounds — alignment offset, axis swap, or units mismatch.');
      } else {
        console.log('Sweep bounds overlap model bounds ✓');
      }
    } else {
      console.warn('No scene positions — JSON missing "position" or hotspots failed to enrich.');
    }
    console.groupEnd();
  },

  /**
   * Debug overlay listing every scene with a position; click jumps the
   * dollhouse camera to that marker and pulses it. Toggle with the chevron
   * button on its header.
   */
  _buildDebugSceneList() {
    if (this._debugListEl) {
      this._debugListEl.hidden = false;
      this._refreshDebugSceneList();
      return;
    }

    const wrap = document.createElement('div');
    wrap.className = 'pano-debug-list';

    const header = document.createElement('div');
    header.className = 'pano-debug-list-header';
    const title = document.createElement('span');
    title.textContent = 'Sweeps';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'pano-debug-list-toggle';
    toggle.textContent = '−';
    toggle.title = 'Replier';
    header.appendChild(title);
    header.appendChild(toggle);

    const body = document.createElement('div');
    body.className = 'pano-debug-list-body';

    toggle.addEventListener('click', () => {
      const collapsed = wrap.classList.toggle('collapsed');
      toggle.textContent = collapsed ? '+' : '−';
    });

    wrap.appendChild(header);
    wrap.appendChild(body);
    this.container.appendChild(wrap);
    this._debugListEl = wrap;
    this._debugListBody = body;
    this._refreshDebugSceneList();
  },

  _refreshDebugSceneList() {
    if (!this._debugListBody) return;
    this._debugListBody.innerHTML = '';
    const scenes = Object.values(this._scenes).filter(s => s.position);
    if (!scenes.length) {
      const empty = document.createElement('div');
      empty.className = 'pano-debug-list-empty';
      empty.textContent = 'No positions';
      this._debugListBody.appendChild(empty);
      return;
    }

    scenes.forEach((sc, i) => {
      const row = document.createElement('div');
      row.className = 'pano-debug-list-row';

      const jump = document.createElement('button');
      jump.type = 'button';
      jump.className = 'pano-debug-jump';
      jump.textContent = (sc.title || sc.id).slice(0, 16) || `#${i + 1}`;
      jump.title = `Frame marker — ${sc.id}`;
      jump.addEventListener('click', () => this.jumpToScene(sc.id));

      const tele = document.createElement('button');
      tele.type = 'button';
      tele.className = 'pano-debug-tele';
      tele.textContent = '→';
      tele.title = 'Teleport into pano';
      tele.addEventListener('click', () => {
        this.setMode('pano');
        this.loadScene(sc.id);
      });

      row.appendChild(jump);
      row.appendChild(tele);
      this._debugListBody.appendChild(row);
    });
  },

  /**
   * Frame the dollhouse camera on a scene's marker. Public — usable from
   * the console for ad-hoc inspection.
   */
  jumpToScene(id) {
    const sc = this._scenes[id];
    if (!sc?.position) {
      console.warn('[PanoViewer] jumpToScene: no position for', id);
      return this;
    }
    if (this._mode !== 'dollhouse') this.setMode('dollhouse');
    if (!this._dollhouseCam || !this._dollhouseCtrls) return this;

    const ov = this._markerOffset || { x: 0, y: 0, z: 0 };
    const target = new THREE.Vector3(
      sc.position.x + ov.x,
      sc.position.y + ov.y,
      sc.position.z + ov.z,
    );
    const bbox   = this._modelBounds || new THREE.Box3().setFromObject(this._model);
    const size   = bbox.getSize(new THREE.Vector3());
    const maxDim = Math.max(size.x, size.y, size.z);
    const dist   = maxDim * 0.35;

    this._dollhouseCtrls.target.copy(target);
    this._dollhouseCam.position.set(
      target.x + dist * 0.7,
      target.y + dist * 0.6,
      target.z + dist * 0.7,
    );
    this._dollhouseCam.lookAt(target);
    this._dollhouseCtrls.update();
    this._pulseMarker(id);
    console.log('[PanoViewer] jumpToScene', id, sc.position);
    return this;
  },

  _pulseMarker(id) {
    if (!this._markersGroup) return;
    const m = this._markersGroup.children.find(c => c.userData.sceneId === id);
    if (!m) return;
    const startScale = m.scale.x;
    const peak = startScale * 3.5;
    const start = performance.now();
    const dur = 800;
    const step = (now) => {
      const t = Math.min(1, (now - start) / dur);
      const k = t < 0.5 ? (t / 0.5) : (1 - (t - 0.5) / 0.5);
      const s = startScale + (peak - startScale) * k;
      m.scale.setScalar(s);
      if (t < 1) requestAnimationFrame(step);
      else m.scale.setScalar(startScale);
    };
    requestAnimationFrame(step);
  },

  _exitDollhouse() {
    if (this._panoBackground) this.scene.background = this._panoBackground;

    this._dollhouseCtrls?.dispose();
    this._dollhouseCtrls = null;
    this._dollhouseCam   = null;

    if (this._markersGroup) {
      this.scene.remove(this._markersGroup);
      this._disposeObject(this._markersGroup);
      this._markersGroup = null;
    }

    this.hsLayer.style.display     = '';
    this.compassEl.style.display   = '';
    this.dollhouseEl.style.display = '';

    if (this._dollhouseExitBtn) this._dollhouseExitBtn.hidden = true;
    if (this._debugListEl) this._debugListEl.hidden = true;
  },

  /**
   * Mini inset (lower-left) — small render of the 3D model that tracks the
   * viewer yaw and highlights the current sweep position.
   * Called once after model load.
   */
  _setupMiniDollhouse() {
    if (!this._model) return;

    const bbox   = this._modelBounds || new THREE.Box3().setFromObject(this._model);
    const center = bbox.getCenter(new THREE.Vector3());
    const size   = bbox.getSize(new THREE.Vector3());
    const maxDim = Math.max(size.x, size.y, size.z);
    const dist   = maxDim * 1.6;

    this._miniRect = { x: 16, y: 0, w: 180, h: 140 };
    this._miniRect.y = this.container.clientHeight - this._miniRect.h - 16;

    if (!this._miniCam) {
      this._miniCam = new THREE.PerspectiveCamera(
        40,
        this._miniRect.w / this._miniRect.h,
        0.1,
        Math.max(1000, maxDim * 10),
      );
      this._miniCam.layers.disable(0);
      this._miniCam.layers.enable(1);
    }
    this._miniCamTarget  = center.clone();
    this._miniCamDist    = dist;
    this._miniCamBasePos = center.clone().add(new THREE.Vector3(dist, dist * 0.85, dist));

    if (this._miniMarker) {
      this.scene.remove(this._miniMarker);
      this._disposeObject(this._miniMarker);
    }
    const markerGeo = new THREE.SphereGeometry(maxDim * 0.015, 12, 10);
    const markerMat = new THREE.MeshBasicMaterial({ color: 0xffffff });
    this._miniMarker = new THREE.Mesh(markerGeo, markerMat);
    this._miniMarker.layers.set(1);
    this._miniMarker.visible = false;
    this.scene.add(this._miniMarker);

    this.dollhouseEl.style.width  = this._miniRect.w + 'px';
    this.dollhouseEl.style.height = this._miniRect.h + 'px';
    this.dollhouseEl.classList.add('pano-dollhouse--mini');

    if (!this._miniResizeBound) {
      this._miniResizeBound = true;
      const update = () => {
        if (!this._miniRect) return;
        this._miniRect.y = this.container.clientHeight - this._miniRect.h - 16;
      };
      window.addEventListener('resize', update);
      this._listeners.push({ target: window, type: 'resize', fn: update });
    }
  },

  _renderMiniInset() {
    const rect = this._miniRect;
    if (!rect) return;
    const r      = this.renderer;
    const center = this._miniCamTarget;
    const dist   = this._miniCamDist;
    const yawRad = this.yaw * Math.PI / 180;

    this._miniCam.position.set(
      center.x + Math.sin(yawRad) * dist,
      center.y + dist * 0.85,
      center.z + Math.cos(yawRad) * dist,
    );
    this._miniCam.lookAt(center);

    if (this._miniMarker) {
      const sc = this._scenes[this._activeId];
      if (sc?.position) {
        this._miniMarker.visible = true;
        const ov = this._markerOffset || { x: 0, y: 0, z: 0 };
        this._miniMarker.position.set(
          sc.position.x + ov.x,
          sc.position.y + ov.y,
          sc.position.z + ov.z,
        );
      } else {
        this._miniMarker.visible = false;
      }
    }

    const H = this.container.clientHeight;
    r.setScissorTest(true);
    r.setViewport(rect.x, H - rect.y - rect.h, rect.w, rect.h);
    r.setScissor (rect.x, H - rect.y - rect.h, rect.w, rect.h);
    r.setClearColor(0x111111, 1);
    r.clearColor();
    r.clearDepth();
    r.render(this.scene, this._miniCam);
    r.setScissorTest(false);
  },

  _buildPanoMarkers() {
    if (this._markersGroup) {
      this.scene.remove(this._markersGroup);
      this._disposeObject(this._markersGroup);
    }
    // Marker radius scaled to model size. Bigger than the last pass — easy
    // to spot at dollhouse framing distance. Inactive markers fade down via
    // baseOpacity, so making them larger doesn't crowd the model.
    const bbox   = this._modelBounds || new THREE.Box3().setFromObject(this._model);
    const size   = bbox.getSize(new THREE.Vector3());
    const maxDim = Math.max(size.x, size.y, size.z) || 10;
    const radius = Math.max(0.18, maxDim * 0.013);

    // Disk anatomy:
    //   glow  — translucent outer ring, fakes a soft drop-shadow
    //   ring  — clean white stroke (the main visual)
    // (the filled centre disc was removed per the latest pass — looked dated.)
    const glowGeo   = new THREE.RingGeometry(radius * 1.55, radius * 2.10, 32);
    const ringGeo   = new THREE.RingGeometry(radius,        radius * 1.42, 32);

    const group = new THREE.Group();
    let count = 0;
    for (const sc of Object.values(this._scenes)) {
      if (!sc.position) continue;
      // Outer glow — fakes a soft drop-shadow on the floor.
      const glowMat = new THREE.MeshBasicMaterial({
        color: 0xffffff, transparent: true, opacity: 0.10,
        depthTest: false, depthWrite: false, side: THREE.DoubleSide,
      });
      const glow = new THREE.Mesh(glowGeo, glowMat);
      glow.rotation.x = -Math.PI / 2;
      glow.renderOrder = 9;

      // Main ring — the dot itself.
      const ringMat = new THREE.MeshBasicMaterial({
        color: 0xffffff, transparent: true, opacity: 0.55,
        depthTest: false, depthWrite: false, side: THREE.DoubleSide,
      });
      const ring = new THREE.Mesh(ringGeo, ringMat);
      ring.rotation.x = -Math.PI / 2;
      ring.renderOrder = 10;

      const wrap = new THREE.Group();
      wrap.position.set(sc.position.x, sc.position.y, sc.position.z);
      wrap.userData.sceneId       = sc.id;
      // _setActiveMarker / _animateMarkers still write to haloMat/coreMat
      // — alias them to the new pair so we don't break those code paths.
      wrap.userData.haloMat       = glowMat;
      wrap.userData.coreMat       = ringMat;
      wrap.userData.baseScale     = 0.9;
      wrap.userData.hoverScale    = 1.25;
      wrap.userData.targetScale   = 0.9;
      wrap.userData.baseOpacity   = 0.55;
      wrap.userData.hoverOpacity  = 0.85;
      wrap.userData.targetOpacity = 0.55;
      glow.userData.sceneId = sc.id;
      ring.userData.sceneId = sc.id;
      wrap.add(glow);
      wrap.add(ring);
      // Layer the whole wrap (children inherit) so dollhouse cam sees it.
      wrap.traverse(c => c.layers.set(1));
      group.add(wrap);
      count++;
    }
    // Marker positions arrive in Three.js Y-up (Blender addon + Matterport
    // snippet both convert at export time). No rotation needed.
    //
    // Auto-center: the Matterport / Blender export origin generally differs
    // from the model bbox center, so the marker cluster lands offset from
    // the model. Translate the group so the sweep centroid matches the model
    // bbox centroid. Manual override via cfg.markerOffset = {x, y, z}.
    const positions = Object.values(this._scenes)
      .filter(s => s.position).map(s => s.position);
    if (positions.length) {
      const centroid = positions.reduce(
        (a, p) => ({ x: a.x + p.x, y: a.y + p.y, z: a.z + p.z }),
        { x: 0, y: 0, z: 0 },
      );
      centroid.x /= positions.length;
      centroid.z /= positions.length;
      const modelCenter = bbox.getCenter(new THREE.Vector3());
      // Auto only on horizontal axes — Y depends on per-project floor origin
      // mismatches we can't infer reliably (model bbox floor isn't always
      // ground; sweep min isn't always lowest floor). Set marker_offset_y
      // manually in the blueprint.
      const auto = {
        x: modelCenter.x - centroid.x,
        y: 0,
        z: modelCenter.z - centroid.z,
      };
      const ov = this.cfg.markerOffset || {};
      this._markerOffset = {
        x: ov.x != null ? ov.x : auto.x,
        y: ov.y != null ? ov.y : auto.y,
        z: ov.z != null ? ov.z : auto.z,
      };
      group.position.set(this._markerOffset.x, this._markerOffset.y, this._markerOffset.z);
      this._markerAutoOffset = auto;
      if (this.cfg.debug) console.log('[PanoViewer] marker offset (Y-up m):',
        { x: +group.position.x.toFixed(2), y: +group.position.y.toFixed(2), z: +group.position.z.toFixed(2) });
    }

    this._markersGroup = group;
    this.scene.add(group);
    if (this.cfg.debug) console.log('[PanoViewer] built', count, 'pano markers (radius', radius.toFixed(2), 'm)');
    // Highlight the currently active scene's marker (if any).
    this._setActiveMarker(this._activeId);
  },

  /** Active marker = clearly bigger + fully bright; inactive shrinks + dims. */
  _setActiveMarker(id) {
    if (!this._markersGroup) return;
    this._markersGroup.children.forEach(wrap => {
      const u = wrap.userData;
      if (!u.haloMat || !u.coreMat) return;
      if (u.sceneId === id) {
        u.baseScale    = 1.7;
        u.hoverScale   = 1.95;
        u.baseOpacity  = 1.0;
        u.hoverOpacity = 1.0;
      } else {
        u.baseScale    = 0.85;
        u.hoverScale   = 1.25;
        u.baseOpacity  = 0.22;
        u.hoverOpacity = 0.6;
      }
      if (this._hoveredNav !== wrap) {
        u.targetScale   = u.baseScale;
        u.targetOpacity = u.baseOpacity;
      }
    });
  },

};
