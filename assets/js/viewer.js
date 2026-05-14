/**
 * GoHéritage — Three.js Viewer with annotation hotspot system
 *
 * Features:
 *   - GLB (DRACO) / OBJ+MTL model loading
 *   - CSS2DRenderer labels anchored to 3D hotspot positions
 *   - Smooth camera fly-to animation
 *   - Blender Empty objects with userData are read as hotspots
 *   - CMS annotation data (JSON) merged with hotspot positions
 *   - Adaptive pixel-ratio for performance on weak GPUs
 *   - Left-panel ↔ 3D label two-way interaction
 */

import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/addons/loaders/DRACOLoader.js';
import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';
import { MTLLoader } from 'three/addons/loaders/MTLLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { CSS2DRenderer, CSS2DObject } from 'three/addons/renderers/CSS2DRenderer.js';

// ── Main init ────────────────────────────────────────────────────────────────

function initViewer(container) {
  const objUrl = container.dataset.obj || null;
  const glbUrl = container.dataset.glb || null;
  const texUrl        = container.dataset.texture        || null;
  const texPreviewUrl = container.dataset.texturePreview || null;

  const dracoPath = container.dataset.dracoPath || null;
  const interiorObjUrl        = container.dataset.objInterior             || null;
  const interiorGlbUrl        = container.dataset.glbInterior             || null;
  const interiorTexUrl        = container.dataset.textureInterior         || null;
  const interiorTexPreviewUrl = container.dataset.textureInteriorPreview  || null;

  const hotspotsJsonUrl = container.dataset.hotspotsJson || null;
  const hotspotsJsonInteriorUrl = container.dataset.hotspotsJsonInterior || null;

  // CMS annotations passed as JSON data attribute
  let cmsAnnotations = [];
  try { cmsAnnotations = JSON.parse(container.dataset.annotations || '[]'); } catch (_) { }

  // Hotspot positions loaded from JSON file(s) (set after fetch)
  var jsonHotspots = { exterior: { hotspots: [] }, interior: { hotspots: [] } };

  var interiorGlbDerivedUrl = interiorGlbUrl || (interiorObjUrl ? interiorObjUrl.replace(/\.obj$/i, '.glb') : null);
  if (!objUrl && !glbUrl && !interiorGlbDerivedUrl && !interiorObjUrl) return;

  // ── Mobile detection ─────────────────────────────────────────────────────
  var isMobile = window.innerWidth <= 768 || ('ontouchstart' in window);

  // ── Renderer ─────────────────────────────────────────────────────────────
  const renderer = new THREE.WebGLRenderer({
    antialias: !isMobile,
    alpha: false,
    powerPreference: isMobile ? 'low-power' : 'default',
  });
  renderer.setPixelRatio(isMobile ? 1 : Math.min(window.devicePixelRatio, 2));
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  container.appendChild(renderer.domElement);

  // ── CSS2D Renderer (label overlay) ───────────────────────────────────────
  const labelRenderer = new CSS2DRenderer();
  labelRenderer.setSize(container.clientWidth, container.clientHeight);
  labelRenderer.domElement.id = 'viewer-labels';
  container.appendChild(labelRenderer.domElement);


  // ── Scene ────────────────────────────────────────────────────────────────
  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0x1a1a1a);
  scene.add(new THREE.AmbientLight(0xffffff, 0.6));

  const dirLight = new THREE.DirectionalLight(0xffffff, 0.8);
  dirLight.position.set(5, 10, 7.5);
  scene.add(dirLight);

  // ── Camera ───────────────────────────────────────────────────────────────
  const camera = new THREE.PerspectiveCamera(
    50, container.clientWidth / container.clientHeight, 0.1, 10000
  );

  // ── Controls ─────────────────────────────────────────────────────────────
  // IMPORTANT: attach to the WebGL canvas, not the CSS2D overlay
  // the overlay has pointer-events:none so it passes events to the canvas
  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;
  controls.screenSpacePanning = true;
  controls.maxDistance = 5000;
  controls.enableZoom = false; // handled manually below so we can call preventDefault

  // ── Zoom ─────────────────────────────────────────────────────────────────
  // preventDefault() must be instant; heavy zoom math is deferred to the
  // animation frame so rapid wheel bursts never delay the scroll block.
  var _overViewer = false;
  var _pendingZoom = 1; // accumulated scale factor, applied each frame

  container.addEventListener('mouseenter', function () { _overViewer = true; });
  container.addEventListener('mouseleave', function () { _overViewer = false; });

  window.addEventListener('wheel', function (e) {
    if (!_overViewer) return;
    e.preventDefault();
    _pendingZoom *= e.deltaY > 0 ? 1.1 : 1 / 1.1;
  }, { passive: false });


  container.addEventListener('touchmove', function (e) {
    e.preventDefault();
  }, { passive: false, capture: true });

  // ── Progress overlay ─────────────────────────────────────────────────────
  const progress = document.createElement('div');
  progress.className = 'viewer-progress';
  progress.innerHTML =
    '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
    '<span class="viewer-progress-text">chargement du modèle\u2026</span>';
  container.appendChild(progress);

  const progressFill = progress.querySelector('.viewer-progress-fill');
  const progressText = progress.querySelector('.viewer-progress-text');

  function updateProgress(loaded, total) {
    if (total > 0) {
      const pct = Math.round((loaded / total) * 100);
      progressFill.style.width = pct + '%';
      progressText.textContent = 'chargement\u2026 ' + pct + '%';
    }
  }

  function hideProgress() {
    progress.style.opacity = '0';
    setTimeout(function () { progress.remove(); }, 400);
  }

  // ── State ────────────────────────────────────────────────────────────────
  var hotspots = [];       // active side's hotspot list
  var activeHotspotId = null;
  var modelCenter = new THREE.Vector3();
  var modelRadius = 1;
  var flyAnimation = null; // active fly-to RAF id

  // ── Dual-model state ─────────────────────────────────────────────────────
  var defaultSide = (container.dataset.defaultSide === 'interior') ? 'interior' : 'exterior';
  // Exterior always loads first; currentSide starts as 'exterior' regardless of
  // defaultSide so that switchModel('interior') in prepareModel isn't a no-op.
  var currentSide = 'exterior';
  var modelObjects = { exterior: null, interior: null };
  var hotspotSets = { exterior: [], interior: [] };

  // ── Fade overlay (used when switching models) ────────────────────────────
  var fadeEl = document.createElement('div');
  fadeEl.className = 'viewer-fade';
  container.appendChild(fadeEl);

  // ── Controls hint (desktop only) ─────────────────────────────────────────
  if (!isMobile) {
    var hint = document.createElement('div');
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

    var hintTimer = null;
    var pointerDown = false;
    function scheduleHint() {
      clearTimeout(hintTimer);
      if (!pointerDown) {
        hintTimer = setTimeout(function () { hint.classList.add('is-visible'); }, 1000);
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
    renderer.domElement.addEventListener('wheel', function () {
      clearTimeout(hintTimer);
      hint.classList.remove('is-visible');
      scheduleHint();
    }, { passive: true });
  }

  // ── Frame camera to fit model ────────────────────────────────────────────
  function frameModel(object) {
    var box = new THREE.Box3().setFromObject(object);
    if (box.isEmpty()) {
       box.setFromCenterAndSize(new THREE.Vector3(0,0,0), new THREE.Vector3(10,10,10));
    }
    var size = box.getSize(new THREE.Vector3());
    var center = box.getCenter(new THREE.Vector3());

    modelCenter.copy(center);
    modelRadius = size.length() / 2 || 1;

    controls.target.copy(center);

    var maxDim = Math.max(size.x, size.y, size.z) || 10;
    var fov = camera.fov * (Math.PI / 180);
    var dist = ((maxDim / 2) / Math.tan(fov / 2) * 0.8) || 10;

    camera.position.copy(center);
    camera.position.y += maxDim * 0.55;
    camera.position.z += dist;
    camera.near = 0.01;
    camera.far = Math.max(10000, maxDim * 10);
    camera.updateProjectionMatrix();
    controls.update();
  }

  // ── Texture loader helper ────────────────────────────────────────────────
  // Disables mipmaps to prevent UV-island edge bleeding (dark seam lines).
  function loadTexture(url, flipY) {
    var tex = new THREE.TextureLoader().load(url);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.flipY = (flipY === undefined) ? true : flipY;
    tex.generateMipmaps = false;
    tex.minFilter = THREE.LinearFilter;
    tex.magFilter = THREE.LinearFilter;
    return tex;
  }

  // ── Progressive texture upgrade ──────────────────────────────────────────
  // Loads a high-res texture in the background; swaps all mesh materials on
  // the model when it arrives.  The low-res preview stays visible until then.
  function upgradeTexture(model, url, flipY, side) {
    new THREE.TextureLoader().load(url, function (tex) {
      tex.colorSpace = THREE.SRGBColorSpace;
      tex.flipY = (flipY === undefined) ? true : flipY;
      tex.generateMipmaps = false;
      tex.minFilter = THREE.LinearFilter;
      tex.magFilter = THREE.LinearFilter;
      var newMat = new THREE.MeshBasicMaterial({ map: tex, side: side });
      model.traverse(function (child) {
        if (!child.isMesh) return;
        var old = Array.isArray(child.material) ? child.material[0] : child.material;
        child.material = newMat;
        if (old && old !== newMat) { if (old.map) old.map.dispose(); old.dispose(); }
      });
    });
  }

  // ── Convert any material to MeshBasicMaterial ────────────────────────────
  // Photogrammetry models have lighting baked into the texture. Rendering them
  // through PBR (MeshStandardMaterial) with scene lights applies lighting a
  // second time, producing an over-bright, blue/white washed-out appearance.
  // This helper swaps any non-basic material for a MeshBasicMaterial that
  // simply displays the colour map without further lighting calculations.
  function toBasicMaterial(m, materialSide) {
    if (m.isMeshBasicMaterial) {
      m.side = materialSide;
      return m;
    }
    var basic = new THREE.MeshBasicMaterial({
      map:         m.map         || null,
      color:       m.map ? 0xffffff : (m.color ? m.color.clone() : new THREE.Color(0x888888)),
      side:        materialSide,
      transparent: m.transparent || false,
      alphaMap:    m.alphaMap    || null,
    });
    m.dispose();
    return basic;
  }

  // ── Prepare model materials ──────────────────────────────────────────────
  function prepareModel(object, side) {
    // Exterior: DoubleSide (opaque backfaces visible from outside)
    // Interior: FrontSide (backfaces transparent, see through walls)
    var materialSide = (side === 'interior') ? THREE.FrontSide : THREE.DoubleSide;

    object.traverse(function (child) {
      if (!child.isMesh || !child.material) return;
      if (Array.isArray(child.material)) {
        child.material = child.material.map(function (m) { return toBasicMaterial(m, materialSide); });
      } else {
        child.material = toBasicMaterial(child.material, materialSide);
      }
    });

    scene.add(object);
    frameModel(object);
    hideProgress();

    // Signal to the page that the viewer has a model rendered — CSS uses this
    // to reveal the fold toggle button.
    document.body.classList.add('viewer-is-ready');

    extractHotspots(object, side || 'exterior');
    buildLabels();
    wireAnnotationPanel();
    wireHotspotBlocks();

    // Store exterior model; build toggle once ready if any interior model is set
    modelObjects.exterior = object;
    hotspotSets.exterior = hotspots.slice();
    if (interiorGlbDerivedUrl || interiorObjUrl) {
      buildToggle();
      // If the CMS toggle is set to interior, switch immediately after exterior loads
      if (defaultSide === 'interior') {
        switchModel('interior');
      }
    }
  }

  // ── Extract hotspots from JSON file or GLB Empties (fallback) ─────────────
  function extractHotspots(root, side) {
    var sideKey = side || 'exterior';

    // Primary: read hotspot positions from the JSON file exported by Blender addon
    if (jsonHotspots && jsonHotspots[sideKey] && jsonHotspots[sideKey].hotspots && jsonHotspots[sideKey].hotspots.length) {
      jsonHotspots[sideKey].hotspots.forEach(function (h) {
        var pos = h.position;
        var entry = {
          id: h.id,
          title: h.title || h.id,
          position: new THREE.Vector3(pos.x, pos.y, pos.z),
          cameraPos: null,
          lookAt: null,
          cameraMode: h.camera_mode || 'fly',
          labelObj: null,
          el: null,
        };
        if (h.camera) {
          entry.cameraPos = new THREE.Vector3(h.camera.x, h.camera.y, h.camera.z);
        }
        if (h.lookat) {
          entry.lookAt = new THREE.Vector3(h.lookat.x, h.lookat.y, h.lookat.z);
        }
        hotspots.push(entry);
      });
    } else {
      // Fallback: extract from GLB Empties (legacy pipeline)
      root.traverse(function (node) {
        var isHotspot = node.userData && (
          node.userData.hotspot === true ||
          node.userData.hotspot === 1 ||
          (typeof node.name === 'string' && node.name.toLowerCase().startsWith('hotspot_'))
        );
        if (!isHotspot) return;
        if (node.isMesh) return;

        var id = node.userData.hotspot_id || node.name;
        var pos = new THREE.Vector3();
        node.getWorldPosition(pos);

        var entry = {
          id: id,
          title: node.userData.title || id,
          position: pos,
          cameraPos: null,
          lookAt: null,
          cameraMode: node.userData.camera_mode || 'fly',
          labelObj: null,
          el: null,
        };

        if (node.userData.camera_x !== undefined) {
          entry.cameraPos = new THREE.Vector3(
            node.userData.camera_x, node.userData.camera_y, node.userData.camera_z
          );
        }
        if (node.userData.lookat_x !== undefined) {
          entry.lookAt = new THREE.Vector3(
            node.userData.lookat_x, node.userData.lookat_y, node.userData.lookat_z
          );
        }

        hotspots.push(entry);
      });
    }

    // CMS annotations: create hotspots for entries with manual coordinates
    cmsAnnotations.forEach(function (ann) {
      var existing = hotspots.find(function (h) { return h.id === ann.id; });
      if (!existing && ann.x !== undefined && ann.y !== undefined && ann.z !== undefined) {
        hotspots.push({
          id: ann.id,
          title: ann.title || ann.id,
          position: new THREE.Vector3(ann.x, ann.y, ann.z),
          cameraPos: null,
          lookAt: null,
          labelObj: null,
          el: null,
        });
      }
    });

    // merge CMS data (titles, camera_mode) into hotspot entries
    hotspots.forEach(function (hs) {
      var cms = cmsAnnotations.find(function (a) { return a.id === hs.id; });
      if (!cms) return;
      if (cms.title) hs.title = cms.title;
      if (cms.camera_mode) hs.cameraMode = cms.camera_mode;
    });
  }

  // ── Build CSS2D labels ───────────────────────────────────────────────────
  // addToScene defaults to true; pass false when pre-building for an inactive model
  function buildLabels(addToScene) {
    hotspots.forEach(function (hs, i) {
      var el = document.createElement('div');
      el.className = 'viewer-label';
      el.dataset.hotspot = hs.id;
      el.innerHTML =
        '<span class="viewer-label__dot"></span>' +
        '<span class="viewer-label__text">' + escHtml(hs.title) + '</span>';

      el.addEventListener('click', function (e) {
        e.stopPropagation();
        activateHotspot(hs.id);
      });

      // mobile: touchend fires before click; prevent ghost click and orbit theft
      el.addEventListener('touchend', function (e) {
        e.preventDefault();
        e.stopPropagation();
        activateHotspot(hs.id);
      }, { passive: false });

      var labelObj = new CSS2DObject(el);
      labelObj.position.copy(hs.position);
      // offset label slightly upward so it doesn't sit right on the surface
      labelObj.position.y += modelRadius * 0.01;
      labelObj.layers.set(0);
      if (addToScene !== false) scene.add(labelObj);

      hs.labelObj = labelObj;
      hs.el = el;
    });
  }

  // ── Wire POI expandable sections (left side, rendered by PHP) ────────────
  function wireAnnotationPanel() {
    // Legacy annotation entries
    var entries = document.querySelectorAll('.annotation-entry[data-hotspot]');
    entries.forEach(function (entry) {
      entry.addEventListener('click', function () {
        activateHotspot(entry.dataset.hotspot);
      });
    });

    // New expandable POI sections — activate hotspot when toggled open
    var poiSections = document.querySelectorAll('.poi-section[data-hotspot]');
    poiSections.forEach(function (section) {
      section.addEventListener('toggle', function () {
        var id = section.dataset.hotspot;
        if (section.open && id !== activeHotspotId) {
          activateHotspot(id);
        } else if (!section.open && id === activeHotspotId) {
          deactivateHotspot(id);
          activeHotspotId = null;
        }
      });
    });
  }

  // ── Listen for goheritage:activate events from hotspot blocks in body text ─
  function wireHotspotBlocks() {
    container.addEventListener('goheritage:activate', function (e) {
      if (e.detail && e.detail.id) {
        activateHotspot(e.detail.id);
      }
    });
    // Also listen on the document so hotspot blocks outside the viewer
    // container (e.g. in the text column) can still reach us.
    document.addEventListener('goheritage:activate', function (e) {
      if (e.detail && e.detail.id) {
        activateHotspot(e.detail.id);
      }
    });
  }

  // ── Ext / Int toggle ─────────────────────────────────────────────────────
  function buildToggle() {
    var toggle = document.createElement('div');
    toggle.className = 'viewer-toggle';
    // buildToggle is always called from prepareModel (exterior path), so at this
    // point currentSide === 'exterior'. switchModel() will flip is-active later
    // if defaultSide === 'interior'.
    toggle.innerHTML =
      '<button class="viewer-toggle__btn is-active" data-side="exterior">Ext\u00e9rieur</button>' +
      '<button class="viewer-toggle__btn" data-side="interior">Int\u00e9rieur</button>';
    container.appendChild(toggle);
    toggle.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.viewer-toggle__btn') : null;
      if (btn && btn.dataset.side !== currentSide && !btn.disabled) {
        switchModel(btn.dataset.side);
      }
    });
  }

  // ── Update model bounds without re-framing the camera ───────────────────
  function updateModelBounds(object) {
    var box = new THREE.Box3().setFromObject(object);
    var size = box.getSize(new THREE.Vector3());
    modelCenter.copy(box.getCenter(new THREE.Vector3()));
    modelRadius = size.length() / 2;
    camera.near = size.length() * 0.001;
    camera.far = size.length() * 10;
    camera.updateProjectionMatrix();
  }

  // ── Switch between exterior / interior models ────────────────────────────
  function switchModel(side) {
    if (side === currentSide || !modelObjects.exterior) return;

    var btns = container.querySelectorAll('.viewer-toggle__btn');
    btns.forEach(function (b) { b.disabled = true; });

    // Capture camera so the angle is preserved across the swap
    var savedPos = camera.position.clone();
    var savedTarget = controls.target.clone();

    // Fade to black
    fadeEl.style.opacity = '1';

    setTimeout(function () {
      // Remove current model and its labels from the scene
      hotspotSets[currentSide].forEach(function (hs) {
        if (hs.labelObj) scene.remove(hs.labelObj);
      });
      if (activeHotspotId) { deactivateHotspot(activeHotspotId); activeHotspotId = null; }
      if (modelObjects[currentSide]) scene.remove(modelObjects[currentSide]);

      currentSide = side;
      btns.forEach(function (b) {
        b.classList.toggle('is-active', b.dataset.side === side);
        b.disabled = false;
      });

      function showSide(obj) {
        scene.add(obj);
        updateModelBounds(obj);
        camera.position.copy(savedPos);
        controls.target.copy(savedTarget);
        controls.update();
        hotspots = hotspotSets[side];
        hotspots.forEach(function (hs) {
          if (hs.labelObj) scene.add(hs.labelObj);
        });
        // Fade back in
        fadeEl.style.opacity = '0';
      }

      if (modelObjects[side]) {
        showSide(modelObjects[side]);
      } else {
        loadInteriorModel(showSide);
      }
    }, 260);
  }

  // ── Dispatch to GLB or OBJ interior loader ───────────────────────────────
  // Prefer the converted GLB (same basename as OBJ); fall back to OBJ
  // (interiorGlbDerivedUrl already declared above for the early-return check)
  interiorGlbDerivedUrl = interiorGlbUrl || (interiorObjUrl
    ? interiorObjUrl.replace(/\.obj$/i, '.glb')
    : null);

  function loadInteriorModel(onReady) {
    if (interiorGlbDerivedUrl) {
      loadInteriorGlb(interiorGlbDerivedUrl, onReady, interiorObjUrl ? function () {
        loadInteriorObj(onReady);
      } : null);
    } else if (interiorObjUrl) {
      loadInteriorObj(onReady);
    }
  }

  // ── Lazy-load interior OBJ (first switch only) ────────────────────────────
  function loadInteriorObj(onReady) {
    container.querySelectorAll('.viewer-progress').forEach(function (el) { el.remove(); });
    var prog = document.createElement('div');
    prog.className = 'viewer-progress';
    prog.innerHTML =
      '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
      '<span class="viewer-progress-text">chargement\u2026</span>';
    container.appendChild(prog);
    var fill = prog.querySelector('.viewer-progress-fill');
    var text = prog.querySelector('.viewer-progress-text');

    var objLoader = new OBJLoader();
    var basePath = interiorObjUrl.substring(0, interiorObjUrl.lastIndexOf('/') + 1);
    var objFilename = interiorObjUrl.substring(interiorObjUrl.lastIndexOf('/') + 1);
    objLoader.setPath(basePath);

    objLoader.load(
      objFilename,
      function (model) {
        if (interiorTexUrl) {
          var initUrl = interiorTexPreviewUrl || interiorTexUrl;
          var mat = new THREE.MeshBasicMaterial({ map: loadTexture(initUrl), side: THREE.FrontSide });
          model.traverse(function (child) { if (child.isMesh) child.material = mat; });
          if (interiorTexPreviewUrl) upgradeTexture(model, interiorTexUrl, true, THREE.FrontSide);
        } else {
          var fallback = new THREE.MeshBasicMaterial({ color: 0x888888, side: THREE.FrontSide });
          model.traverse(function (child) { if (child.isMesh) child.material = fallback; });
        }

        // Same hotspot extraction pattern as loadInteriorGlb
        var prevHotspots = hotspots;
        var prevRadius = modelRadius;
        var prevCenter = modelCenter.clone();

        // Blender exports Z-up; correct to Three.js Y-up
        model.rotation.x = -Math.PI / 2;

        var box = new THREE.Box3().setFromObject(model);
        modelRadius = box.getSize(new THREE.Vector3()).length() / 2;
        modelCenter.copy(box.getCenter(new THREE.Vector3()));

        hotspots = [];
        extractHotspots(model, 'interior');
        buildLabels(false);
        hotspotSets.interior = hotspots;

        hotspots = prevHotspots;
        modelRadius = prevRadius;
        modelCenter.copy(prevCenter);

        modelObjects.interior = model;

        prog.style.opacity = '0';
        setTimeout(function () { prog.remove(); }, 400);
        onReady(model);
      },
      function (xhr) {
        if (xhr.total > 0) {
          var pct = Math.round((xhr.loaded / xhr.total) * 100);
          fill.style.width = pct + '%';
          text.textContent = 'chargement\u2026 ' + pct + '%';
        }
      },
      function (err) {
        console.error('interior obj load error:', err);
        text.textContent = 'erreur de chargement';
      }
    );
  }

  // ── Lazy-load the interior GLB (first switch only) ───────────────────────
  function loadInteriorGlb(url, onReady, onFail) {
    container.querySelectorAll('.viewer-progress').forEach(function (el) { el.remove(); });
    var prog = document.createElement('div');
    prog.className = 'viewer-progress';
    prog.innerHTML =
      '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
      '<span class="viewer-progress-text">chargement\u2026</span>';
    container.appendChild(prog);
    var fill = prog.querySelector('.viewer-progress-fill');
    var text = prog.querySelector('.viewer-progress-text');

    var loader = new GLTFLoader();
    if (dracoPath) {
      var dl = new DRACOLoader();
      dl.setDecoderPath(dracoPath);
      loader.setDRACOLoader(dl);
    }

    loader.load(
      url,
      function (gltf) {
        var model = gltf.scene;

        // Apply texture if provided (GLB converted from OBJ won't have embedded texture)
        if (interiorTexUrl) {
          var initUrl = interiorTexPreviewUrl || interiorTexUrl;
          var mat = new THREE.MeshBasicMaterial({ map: loadTexture(initUrl, false), side: THREE.FrontSide });
          model.traverse(function (child) { if (child.isMesh) child.material = mat; });
          if (interiorTexPreviewUrl) upgradeTexture(model, interiorTexUrl, false, THREE.FrontSide);
        } else {
          model.traverse(function (child) {
            if (!child.isMesh || !child.material) return;
            if (Array.isArray(child.material)) {
              child.material = child.material.map(function (m) { return toBasicMaterial(m, THREE.FrontSide); });
            } else {
              child.material = toBasicMaterial(child.material, THREE.FrontSide);
            }
          });
        }

        // Blender exports Z-up; correct to Three.js Y-up
        model.rotation.x = -Math.PI / 2;

        // Temporarily borrow globals to build hotspot labels without touching the scene
        var prevHotspots = hotspots;
        var prevRadius = modelRadius;
        var prevCenter = modelCenter.clone();

        var box = new THREE.Box3().setFromObject(model);
        modelRadius = box.getSize(new THREE.Vector3()).length() / 2;
        modelCenter.copy(box.getCenter(new THREE.Vector3()));

        hotspots = [];
        extractHotspots(model, 'interior');
        buildLabels(false); // create CSS2DObjects, don't add to scene yet
        hotspotSets.interior = hotspots;

        // Restore exterior state
        hotspots = prevHotspots;
        modelRadius = prevRadius;
        modelCenter.copy(prevCenter);

        modelObjects.interior = model;

        prog.style.opacity = '0';
        setTimeout(function () { prog.remove(); }, 400);

        onReady(model);
      },
      function (xhr) {
        if (xhr.total > 0) {
          var pct = Math.round((xhr.loaded / xhr.total) * 100);
          fill.style.width = pct + '%';
          text.textContent = 'chargement\u2026 ' + pct + '%';
        }
      },
      function (err) {
        console.warn('interior glb load failed, trying obj:', err);
        prog.remove();
        if (onFail) onFail();
        else text.textContent = 'erreur de chargement';
      }
    );
  }

  // ── Activate a hotspot ───────────────────────────────────────────────────
  function activateHotspot(id) {
    var hs = hotspots.find(function (h) { return h.id === id; });
    if (!hs) return;

    // deactivate previous
    if (activeHotspotId) deactivateHotspot(activeHotspotId);
    activeHotspotId = id;

    // highlight 3D label
    if (hs.el) hs.el.classList.add('is-active');

    // open + scroll the matching <details> section in the left panel
    var poiSection = document.querySelector('.poi-section[data-hotspot="' + id + '"]');
    if (poiSection && !poiSection.open) {
      poiSection.open = true;
      var summary = poiSection.querySelector('.poi-section__summary');
      if (summary) {
        summary.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        poiSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

    // On mobile: open the drawer only when there's a section linked to this hotspot
    if (isMobile && poiSection) {
      var projectContent = document.getElementById('project-content');
      if (projectContent && !projectContent.classList.contains('is-expanded')) {
        projectContent.classList.add('is-expanded');
        document.body.style.overflow = 'hidden';
      }
    }

    // camera behaviour depends on mode
    var targetPos = hs.position.clone();

    // Compute the camera destination.
    // If a position was stored in Blender, use it; otherwise approach
    // from the direction of hotspot → model center at a sensible distance.
    var cameraTarget;
    if (hs.cameraPos) {
      cameraTarget = hs.cameraPos.clone();
    } else {
      // Approach from outward direction (hotspot → away from model center),
      // biased upward so the camera is never below or inside the model.
      var dir = targetPos.clone().sub(modelCenter).normalize();
      if (dir.length() < 0.01) dir.set(0, 0, 1);
      dir.y += 1.4;
      dir.normalize();
      cameraTarget = targetPos.clone().add(dir.multiplyScalar(modelRadius * 0.45));
    }

    var lookAtTarget = hs.lookAt ? hs.lookAt.clone() : targetPos.clone();

    if (hs.cameraMode === 'auto-orbit') {
      // Auto-orbit: fly to stored position, then start 360° auto-orbit
      flyTo(cameraTarget, lookAtTarget, 1.2, function () {
        startAutoOrbit(lookAtTarget);
      });
    } else {
      // Both "fly" and "orbit" fly to the stored Blender position.
      // "orbit" simply re-centers the orbit pivot so subsequent user
      // interaction orbits around the hotspot naturally.
      flyTo(cameraTarget, lookAtTarget, 1.2);
    }
  }

  function deactivateHotspot(id) {
    var hs = hotspots.find(function (h) { return h.id === id; });
    if (hs && hs.el) hs.el.classList.remove('is-active');

    stopAutoOrbit();
    controls.enabled = true;

    var poiSection = document.querySelector('.poi-section[data-hotspot="' + id + '"]');
    if (poiSection && poiSection.open) poiSection.open = false;
  }

  // ── Smooth camera fly-to ─────────────────────────────────────────────────
  function flyTo(targetCamPos, targetLookAt, duration, onComplete) {
    // cancel any existing fly or auto-orbit animation
    if (flyAnimation) cancelAnimationFrame(flyAnimation);
    stopAutoOrbit();

    var startPos = camera.position.clone();
    var startTarget = controls.target.clone();
    var startTime = performance.now();
    var dur = (duration || 1.2) * 1000;

    controls.enabled = false;

    function tick(now) {
      var elapsed = now - startTime;
      var t = Math.min(elapsed / dur, 1);
      // easeInOutCubic
      var ease = t < 0.5
        ? 4 * t * t * t
        : 1 - Math.pow(-2 * t + 2, 3) / 2;

      camera.position.lerpVectors(startPos, targetCamPos, ease);
      controls.target.lerpVectors(startTarget, targetLookAt, ease);
      controls.update();

      if (t < 1) {
        flyAnimation = requestAnimationFrame(tick);
      } else {
        flyAnimation = null;
        // Only re-enable controls if nothing is taking over (e.g. auto-orbit)
        if (typeof onComplete === 'function') {
          onComplete();
        } else {
          controls.enabled = true;
        }
      }
    }

    flyAnimation = requestAnimationFrame(tick);
  }

  // ── Auto-orbit (360° rotation around a pivot) ────────────────────────────
  var autoOrbitRAF = null;

  function startAutoOrbit(pivot) {
    stopAutoOrbit();
    controls.enabled = false;

    var offset = camera.position.clone().sub(pivot);
    var angle = Math.atan2(offset.x, offset.z);
    var elevY = offset.y;
    // Use XZ-only radius so the orbit circle matches exactly where the fly landed
    var radius = Math.sqrt(offset.x * offset.x + offset.z * offset.z);
    var speed = 0.3; // radians per second

    var lastTime = performance.now();

    function orbitTick(now) {
      var dt = (now - lastTime) / 1000;
      lastTime = now;
      angle += speed * dt;

      camera.position.set(
        pivot.x + Math.sin(angle) * radius,
        pivot.y + elevY,
        pivot.z + Math.cos(angle) * radius
      );
      controls.target.copy(pivot);
      controls.update();

      autoOrbitRAF = requestAnimationFrame(orbitTick);
    }

    autoOrbitRAF = requestAnimationFrame(orbitTick);

    // Cancel auto-orbit on any user interaction
    var cancelEvents = ['pointerdown', 'wheel', 'touchstart'];
    function cancelAutoOrbit() {
      stopAutoOrbit();
      controls.enabled = true;
      cancelEvents.forEach(function (ev) {
        renderer.domElement.removeEventListener(ev, cancelAutoOrbit);
      });
    }
    cancelEvents.forEach(function (ev) {
      renderer.domElement.addEventListener(ev, cancelAutoOrbit, { once: true });
    });
  }

  function stopAutoOrbit() {
    if (autoOrbitRAF) {
      cancelAnimationFrame(autoOrbitRAF);
      autoOrbitRAF = null;
    }
  }


  // ── Derive the converted GLB url from the OBJ url (same basename, .glb ext) ─
  var exteriorGlbUrl = glbUrl || (objUrl
    ? objUrl.replace(/\.obj$/i, '.glb')
    : null);

  // ── Fallback: start with interior as the only / primary model ───────────
  // Called when the exterior model fails to load but an interior model exists.
  function startAsInteriorOnly() {
    // Remove any existing progress overlays (including the original one)
    container.querySelectorAll('.viewer-progress').forEach(function (el) { el.remove(); });
    var prog = document.createElement('div');
    prog.className = 'viewer-progress';
    prog.innerHTML =
      '<div class="viewer-progress-bar"><div class="viewer-progress-fill"></div></div>' +
      '<span class="viewer-progress-text">chargement…</span>';
    container.appendChild(prog);

    loadInteriorModel(function (object) {
      scene.add(object);
      frameModel(object);
      prog.style.opacity = '0';
      setTimeout(function () { prog.remove(); }, 400);
      document.body.classList.add('viewer-is-ready');
      currentSide = 'interior';
      hotspots = hotspotSets.interior;
      hotspots.forEach(function (hs) {
        if (hs.labelObj) scene.add(hs.labelObj);
      });
      wireAnnotationPanel();
      wireHotspotBlocks();
    });
  }

  // ── Load exterior model — GLB (converted from OBJ) preferred, OBJ fallback ─
  function loadExteriorGlb(onFail) {
    var loader = new GLTFLoader();
    if (dracoPath) {
      var dl = new DRACOLoader();
      dl.setDecoderPath(dracoPath);
      loader.setDRACOLoader(dl);
    }
    loader.load(
      exteriorGlbUrl,
      function (gltf) {
        var model = gltf.scene;
        // Blender exports Z-up; correct to Three.js Y-up
        if (objUrl) model.rotation.x = -Math.PI / 2;
        // Apply texture when GLB was converted from OBJ (no embedded texture)
        if (texUrl && objUrl) {
          var initUrl = texPreviewUrl || texUrl;
          var mat = new THREE.MeshBasicMaterial({ map: loadTexture(initUrl, false), side: THREE.DoubleSide });
          model.traverse(function (child) { if (child.isMesh) child.material = mat; });
          if (texPreviewUrl) upgradeTexture(model, texUrl, false, THREE.DoubleSide);
        }
        prepareModel(model, 'exterior');
      },
      function (xhr) { updateProgress(xhr.loaded, xhr.total); },
      function (err) {
        console.warn('glb load failed, falling back:', err);
        if (onFail) {
          onFail();
        } else if (interiorGlbDerivedUrl || interiorObjUrl) {
          startAsInteriorOnly();
        } else {
          progressText.textContent = 'erreur de chargement';
        }
      }
    );
  }

  function loadExteriorObj() {
    var manager = new THREE.LoadingManager();
    manager.onError = function (url) {
      console.error('[viewer] exterior model load failed:', url);
      if (interiorGlbDerivedUrl || interiorObjUrl) {
        startAsInteriorOnly();
      } else {
        progressText.textContent = 'erreur de chargement';
      }
    };

    var objLoader = new OBJLoader(manager);
    var basePath = objUrl.substring(0, objUrl.lastIndexOf('/') + 1);
    var objFilename = objUrl.substring(objUrl.lastIndexOf('/') + 1);
    objLoader.setPath(basePath);

    objLoader.load(
      objFilename,
      function (object) {
        if (texUrl) {
          var initUrl = texPreviewUrl || texUrl;
          var mat = new THREE.MeshBasicMaterial({ map: loadTexture(initUrl), side: THREE.DoubleSide });
          object.traverse(function (child) { if (child.isMesh) child.material = mat; });
          if (texPreviewUrl) upgradeTexture(object, texUrl, true, THREE.DoubleSide);
        } else {
          var fallback = new THREE.MeshBasicMaterial({ color: 0x888888, side: THREE.DoubleSide });
          object.traverse(function (child) { if (child.isMesh) child.material = fallback; });
        }
        // Blender exports Z-up; correct to Three.js Y-up
        object.rotation.x = -Math.PI / 2;
        prepareModel(object, 'exterior');
      },
      function (xhr) { updateProgress(xhr.loaded, xhr.total); },
      function (err) { console.error('obj load error:', err); }
    );
  }

  function startLoading() {
    if (exteriorGlbUrl) {
      loadExteriorGlb(objUrl ? loadExteriorObj : null);
    } else if (objUrl) {
      loadExteriorObj();
    } else if (interiorGlbDerivedUrl || interiorObjUrl) {
      // No exterior model — load interior directly as the starting view
      loadInteriorModel(function (object) {
        scene.add(object);
        frameModel(object);
        hideProgress();
        // Activate the interior hotspot set and add labels to the scene
        currentSide = 'interior';
        hotspots = hotspotSets.interior;
        hotspots.forEach(function (hs) {
          if (hs.labelObj) scene.add(hs.labelObj);
        });
        wireAnnotationPanel();
        wireHotspotBlocks();
      });
    }
  }

  // Fetch JSON hotspot files (exterior + interior), merge into jsonHotspots, then load model
  var fetchJobs = [];

  if (hotspotsJsonUrl) {
    fetchJobs.push(
      fetch(hotspotsJsonUrl).then(function (r) { return r.json(); }).then(function (data) {
        if (data.exterior && data.exterior.hotspots) jsonHotspots.exterior = data.exterior;
        if (data.interior && data.interior.hotspots && data.interior.hotspots.length)
          jsonHotspots.interior = data.interior;
      }).catch(function (err) { console.warn('exterior hotspots json failed:', err); })
    );
  }

  if (hotspotsJsonInteriorUrl) {
    fetchJobs.push(
      fetch(hotspotsJsonInteriorUrl).then(function (r) { return r.json(); }).then(function (data) {
        if (data.interior && data.interior.hotspots) jsonHotspots.interior = data.interior;
        if (data.exterior && data.exterior.hotspots && data.exterior.hotspots.length
          && !jsonHotspots.exterior.hotspots.length)
          jsonHotspots.exterior = data.exterior;
      }).catch(function (err) { console.warn('interior hotspots json failed:', err); })
    );
  }

  Promise.all(fetchJobs).finally(startLoading);

  // ── Adaptive pixel ratio (performance) ───────────────────────────────────
  var frameCount = 0;
  var lastFpsCheck = performance.now();
  var currentPixelRatio = isMobile ? 1 : Math.min(window.devicePixelRatio, 2);

  function checkPerformance() {
    if (isMobile) return; // already locked to 1 on mobile
    frameCount++;
    var now = performance.now();
    var delta = now - lastFpsCheck;
    if (delta >= 2000) {
      var fps = (frameCount / delta) * 1000;
      frameCount = 0;
      lastFpsCheck = now;

      // drop pixel ratio if FPS is too low
      if (fps < 25 && currentPixelRatio > 1) {
        currentPixelRatio = 1;
        renderer.setPixelRatio(1);
      }
    }
  }

  // ── Render loop ──────────────────────────────────────────────────────────
  function animate() {
    requestAnimationFrame(animate);

    // Dynamic controls speed — scale damping, pan, and zoom relative to
    // camera-to-target distance so close-up navigation stays responsive.
    var dist = camera.position.distanceTo(controls.target);
    var ratio = Math.max(dist / Math.max(modelRadius, 1), 0.05);
    controls.dampingFactor = 0.08 * Math.max(ratio, 0.3);
    controls.panSpeed = Math.max(ratio * 0.8, 0.15);

    // Apply any accumulated zoom from wheel events (deferred from the listener
    // so rapid scroll bursts don't block the preventDefault call).
    if (_pendingZoom !== 1) {
      var zoomDir = camera.position.clone().sub(controls.target);
      camera.position.copy(controls.target).addScaledVector(zoomDir, _pendingZoom);
      _pendingZoom = 1;
    }

    // Skip controls.update() while a fly animation is active — the fly tick
    // calls controls.update() itself. Double-updating with enableDamping on
    // corrupts the damping state and can freeze the camera.
    if (!flyAnimation) controls.update();
    renderer.render(scene, camera);
    labelRenderer.render(scene, camera);
    checkPerformance();
  }
  animate();

  // ── Handle resize ────────────────────────────────────────────────────────
  var observer = new ResizeObserver(function () {
    var w = container.clientWidth;
    var h = container.clientHeight;
    if (w === 0 || h === 0) return; // Prevent corrupting the projection matrix if element is hidden
    renderer.setSize(w, h);
    labelRenderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  });
  observer.observe(container);

  // ── tap on empty canvas deactivates active hotspot ───────────────────────
  // use pointerdown/pointerup so we can distinguish a tap from an orbit drag
  // orbitControls consumes pointermove, but we track displacement ourselves
  var _pdX = 0, _pdY = 0;

  renderer.domElement.addEventListener('pointerdown', function (e) {
    _pdX = e.clientX;
    _pdY = e.clientY;
  });

  renderer.domElement.addEventListener('pointerup', function (e) {
    var dx = e.clientX - _pdX;
    var dy = e.clientY - _pdY;
    // treat as tap only if the pointer barely moved (< 6 px — not an orbit drag)
    if (Math.sqrt(dx * dx + dy * dy) < 6 && activeHotspotId) {
      deactivateHotspot(activeHotspotId);
      activeHotspotId = null;
    }
  });
}

// ── Utility ──────────────────────────────────────────────────────────────────

function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

// ── Auto-init ────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
  var container = document.getElementById('viewer-3d');
  if (container) {
    if (container.dataset.deferLoad === 'true') {
      container.addEventListener('goheritage:load', function() {
        initViewer(container);
      }, { once: true });
    } else {
      initViewer(container);
      // Non-embedded: viewer starts loading immediately, reveal the fold toggle
      // right away (the model being loaded shows via the progress bar).
      document.body.classList.add('viewer-is-ready');
    }
  }
});
